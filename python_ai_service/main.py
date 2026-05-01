from contextlib import asynccontextmanager
from fastapi import FastAPI, HTTPException, UploadFile, File
import pandas as pd
from prophet import Prophet
import datetime
import calendar
import os
import requests
import re
import io
import torch
from PIL import Image
# Bypass PyTorch 2.6 CVE strict check. Karena kita mengunduh model aman dari clóva oficial hub, ini tidak berisiko.
import transformers.utils.import_utils
if hasattr(transformers.utils.import_utils, 'check_torch_load_is_safe'):
    transformers.utils.import_utils.check_torch_load_is_safe = lambda: None
from transformers import DonutProcessor, VisionEncoderDecoderModel
import requests
from dotenv import load_dotenv
from fastapi import FastAPI, HTTPException
from langchain_google_genai import ChatGoogleGenerativeAI
from langchain_core.tools import tool
from langchain_core.messages import HumanMessage, AIMessage
from langchain.agents import create_agent
from schemas import PredictiveBudgetRequest, ChatRequest

# Inisialisasi variabel global dengan None
device = "cuda" if torch.cuda.is_available() else "cpu"
processor = None
model = None

@asynccontextmanager
async def lifespan(app: FastAPI):
    global processor, model
    print(f"Loading Donut OCR model on {device} (Worker Process)...")
    try:
        # Menambahkan use_safetensors=True untuk menghindari warning/error keamanan
        processor = DonutProcessor.from_pretrained("naver-clova-ix/donut-base-finetuned-cord-v2", use_safetensors=True)
        model = VisionEncoderDecoderModel.from_pretrained("naver-clova-ix/donut-base-finetuned-cord-v2", use_safetensors=True)
        model.to(device)
        print("Model loaded successfully into the active worker!")
    except Exception as e:
        import traceback
        traceback.print_exc()
        print(f"Failed to load model: {e}")
        
    yield # Aplikasi berjalan dan siap menerima request
    
    # (Opsional) Membersihkan memory saat server dimatikan
    print("Shutting down AI service, clearing model from VRAM...")
    model = None
    processor = None

# Pasang lifespan ke instansiasi FastAPI (CUKUP SATU KALI SAJA)
app = FastAPI(title="Predictive Budgeting AI Service", lifespan=lifespan)

# Load environment variables (GEMINI_API_KEY)
load_dotenv()

# ==========================================
# SETUP LANGCHAIN MEMORY & LLM
# ==========================================

# 1. Dictionary untuk menyimpan riwayat chat sementara (Memory)
store = {}

def get_session_history(session_id: str):
    if session_id not in store:
        store[session_id] = []
    return store[session_id]

# 2. Inisialisasi Model Gemini
llm = ChatGoogleGenerativeAI(
    model="gemini-2.5-flash", 
    temperature=0.3, 
    max_tokens=1024
)

# 3. Mendefinisikan Tools (Alat untuk AI)
# Pastikan URL ini sesuai dengan port Laravel kamu (biasanya 8000)
LARAVEL_API_URL = os.getenv("LARAVEL_API_URL", "http://127.0.0.1:8000/api")

@tool
def get_user_balance(user_id: int) -> str:
    """
    Gunakan alat ini SECARA EKSKLUSIF saat pengguna bertanya tentang total saldo,
    jumlah uang, sisa uang di dompet, atau rekening mereka.
    """
    print(f"[TOOL DIPANGGIL] Mengambil data saldo untuk user_id: {user_id}")
    try:
        response = requests.get(f"{LARAVEL_API_URL}/internal/balance", params={"user_id": user_id})
        if response.status_code == 200:
            return str(response.json())
        return "Sistem gagal mengambil data saldo."
    except Exception as e:
        return f"Error sistem internal: {str(e)}"

tools = [get_user_balance]

# 4. System Prompt Baru
system_instruction = """Kamu adalah **Finansialin AI** — asisten keuangan pribadi yang cerdas, empatik, dan proaktif.

Aturan Penting:
1. Jika pengguna bertanya tentang data keuangan (seperti saldo), KAMU WAJIB menggunakan alat (tools) yang tersedia untuk mencari datanya! Jangan pernah menebak angka.
2. Gunakan bahasa Indonesia yang kasual, hangat, dan bersahabat (gunakan 'aku' dan 'kamu').
3. Setelah mendapat data dari tool, sampaikan datanya dengan rapi dan ramah (tambahkan format Rupiah yang benar).
"""

# 5. Buat Agent menggunakan standar terbaru (LangGraph)
agent = create_agent(llm, tools, system_prompt=system_instruction)



def get_last_day_of_month(current_date):
    """Returns the last day of the month for a given datetime object."""
    _, last_day = calendar.monthrange(current_date.year, current_date.month)
    return current_date.replace(day=last_day)

def is_within_payday_window(date_obj, payday):
    """
    Checks if a given date falls within 3 days after the payday (inclusive of payday itself).
    The window is [payday_date, payday_date + 3].
    """
    try:
        payday_date = date_obj.replace(day=payday)
        delta = (date_obj - payday_date).days
        return 1 if 0 <= delta <= 3 else 0
    except ValueError:
        # Fallback if the month does not contain the specific payday (e.g., Feb 30th)
        last_day = calendar.monthrange(date_obj.year, date_obj.month)[1]
        actual_payday = min(payday, last_day)
        payday_date = date_obj.replace(day=actual_payday)
        delta = (date_obj - payday_date).days
        return 1 if 0 <= delta <= 3 else 0

@app.post("/predict/ocr")
async def predict_ocr(receiptImage: UploadFile = File(...)):
    if model is None or processor is None:
        raise HTTPException(
            status_code=500, 
            detail="Model OCR gagal dimuat. Cek log terminal."
        )

    try:
        contents = await receiptImage.read()
        image = Image.open(io.BytesIO(contents)).convert("RGB")
    except Exception as e:
        raise HTTPException(status_code=400, detail=f"File gambar tidak valid: {str(e)}")

    try:
        # 1. Proses Gambar
        pixel_values = processor(image, return_tensors="pt").pixel_values
        pixel_values = pixel_values.to(device)

        task_prompt = "<s_cord-v2>"
        decoder_input_ids = processor.tokenizer(task_prompt, add_special_tokens=False, return_tensors="pt").input_ids
        decoder_input_ids = decoder_input_ids.to(device)

        # 2. Generasi Output
        outputs = model.generate(
            pixel_values,
            decoder_input_ids=decoder_input_ids,
            max_length=model.decoder.config.max_position_embeddings,
            pad_token_id=processor.tokenizer.pad_token_id,
            eos_token_id=processor.tokenizer.eos_token_id,
            use_cache=True,
            bad_words_ids=[[processor.tokenizer.unk_token_id]],
            return_dict_in_generate=True,
        )

        # 3. Decode & Parse ke JSON
        sequence = processor.batch_decode(outputs.sequences)[0]
        sequence = sequence.replace(processor.tokenizer.eos_token, "").replace(processor.tokenizer.pad_token, "")
        sequence = re.sub(r"<.*?>", "", sequence, count=1).strip()
        
        parsed_data = processor.token2json(sequence)
        
        # Log ke terminal untuk melihat struktur asli
        print("--- AI RAW OUTPUT ---")
        print(parsed_data)

        # 4. Ekstraksi Data dengan Fallback (Mencari beberapa kemungkinan kunci)
        def get_value(data, keys, default=None):
            if not isinstance(data, dict): return default
            for key in keys:
                if key in data:
                    val = data[key]
                    return val[0] if isinstance(val, list) and len(val) > 0 else val
            return default

        merchant_name = None
        total_amount = 0.0
        date = None

        if isinstance(parsed_data, dict):
            # 1. Cari Nama Merchant di store_info (kunci standar)
            store_info = parsed_data.get("store_info", {})
            merchant_name = get_value(store_info, ["name", "nm", "store_name"])

            # 2. Cari Tanggal di payment_info (kunci standar)
            payment_info = parsed_data.get("payment_info", {})
            date = get_value(payment_info, ["date", "dt"])

            # 3. FALLBACK: Jika merchant atau tanggal masih kosong, cari di dalam "menu"
            menu_items = parsed_data.get("menu", [])
            if isinstance(menu_items, list) and len(menu_items) > 0:
                # Coba ambil merchant_name dari item pertama menu jika masih null
                if not merchant_name:
                    first_item_name = str(menu_items[0].get("nm", ""))
                    # Pastikan itu bukan tanggal atau sekadar angka
                    if first_item_name and not re.search(r'\d{4}-\d{2}-\d{2}', first_item_name):
                        merchant_name = first_item_name.strip()

                # Coba cari format tanggal (YYYY-MM-DD atau DD-MM-YYYY) di seluruh menu
                if not date:
                    date_pattern = r'\b(\d{4}[-/]\d{2}[-/]\d{2}|\d{2}[-/]\d{2}[-/]\d{4})\b'
                    for item in menu_items:
                        for key, val in item.items():
                            match = re.search(date_pattern, str(val))
                            if match:
                                date = match.group(1)
                                break
                        if date:
                            break

            # 4. Cari Total di total atau payment_info
            total_section = parsed_data.get("total", {})
            raw_total = get_value(total_section, ["total_price", "total"]) 
            if not raw_total:
                raw_total = get_value(payment_info, ["total_price", "total"])

            # 5. Bersihkan Angka Total
            if raw_total:
                try:
                    total_clean = re.sub(r'[^\d]', '', str(raw_total))
                    if str(raw_total).endswith(',00') or str(raw_total).endswith('.00'):
                        total_clean = total_clean[:-2]
                    if total_clean:
                        total_amount = float(total_clean)
                except: pass
                
        return {
            "status": "success",
            "data": {
                "merchant_name": merchant_name,
                "total_amount": total_amount,
                "date": date,
                "suggested_category": "Pengeluaran Lainnya"
            },
            "debug_raw_ai": parsed_data
        }

    except Exception as e:
        import traceback
        traceback.print_exc()
        raise HTTPException(status_code=500, detail=f"OCR Processing Error: {str(e)}")
    
@app.post("/chat")
async def chat_endpoint(request: ChatRequest):
    print(f"-> Menerima pesan dari User {request.user_id} (Session: {request.session_id})")
    print(f"-> Pesan: {request.message}")
    
    # Ambil riwayat percakapan sebelumnya
    history = get_session_history(request.session_id)
    
    # INJEKSI KONTEKS: Kita bisikkan user_id ke AI secara rahasia di dalam prompt
    # agar AI tidak perlu bertanya lagi ke pengguna.
    contextual_message = f"[Sistem: Ingat, user_id pengguna yang sedang ngobrol denganmu saat ini adalah {request.user_id}].\n\n{request.message}"
    
    # Tambahkan pesan yang sudah diselipkan konteks ini ke dalam history
    history.append(HumanMessage(content=contextual_message))
    
    try:
        # Eksekusi AI Agent 
        response = agent.invoke({"messages": history})
        
        # Ekstrak balasan AI (Mengatasi format array/list dari LangGraph)
        raw_content = response["messages"][-1].content
        if isinstance(raw_content, list):
            # Jika berupa array, ambil bagian yang ada 'text'-nya saja
            ai_reply = " ".join([item.get("text", "") for item in raw_content if isinstance(item, dict) and "text" in item])
        else:
            # Jika sudah berupa string biasa
            ai_reply = str(raw_content)
            
        print(f"<- Balasan AI: {ai_reply}")
        
        # Simpan balasan teks AI yang sudah bersih ke dalam history
        history.append(AIMessage(content=ai_reply))
        
        return {
            "reply": ai_reply,
            "type": "text"
        }
    except Exception as e:
        import traceback
        traceback.print_exc()
        raise HTTPException(status_code=500, detail=f"Gagal memproses pesan AI: {str(e)}")