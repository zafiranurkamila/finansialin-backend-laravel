import os
import requests
from dotenv import load_dotenv
from langchain_google_genai import ChatGoogleGenerativeAI
from langchain_core.tools import tool
from langchain_core.messages import HumanMessage, AIMessage
from langchain.agents import create_agent

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
LARAVEL_API_URL = os.getenv("LARAVEL_API_URL", "http://127.0.0.1:8000/api")

@tool
def get_recent_transactions(user_id: int, limit: int = 5) -> str:
    """
    Gunakan alat ini SECARA EKSKLUSIF saat pengguna bertanya tentang riwayat transaksi terakhir mereka, 
    pengeluaran terbaru, pemasukan terbaru, atau "uangku habis buat beli apa saja".
    Alat ini mengembalikan daftar transaksi terbaru.
    """
    print(f"[TOOL DIPANGGIL] Mengambil {limit} transaksi terakhir untuk user_id: {user_id}")
    try:
        response = requests.get(
            f"{LARAVEL_API_URL}/internal/recent-transactions", 
            params={"user_id": user_id, "limit": limit}
        )
        if response.status_code == 200:
            return str(response.json())
        return "Sistem gagal mengambil riwayat transaksi."
    except Exception as e:
        return f"Error sistem internal: {str(e)}"

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

tools = [get_user_balance, get_recent_transactions]

# 4. System Prompt Baru
system_instruction = """Kamu adalah **Finansialin AI** — asisten keuangan pribadi yang cerdas, empatik, dan proaktif.

Aturan Penting:
1. Jika pengguna bertanya tentang data keuangan (seperti saldo atau riwayat), KAMU WAJIB menggunakan alat (tools) yang tersedia untuk mencari datanya! Jangan pernah menebak angka.
2. Gunakan bahasa Indonesia yang kasual, hangat, dan bersahabat (gunakan 'aku' dan 'kamu').
3. Setelah mendapat data dari tool, sampaikan datanya dengan rapi dan ramah (tambahkan format Rupiah yang benar).
"""

# 5. Buat Agent
agent = create_agent(llm, tools, system_prompt=system_instruction)

def process_chat(user_id: int, session_id: str, message: str) -> str:
    """
    Memproses pesan masuk dari pengguna dan mengembalikan balasan dari agen AI.
    """
    history = get_session_history(session_id)
    
    # INJEKSI KONTEKS
    contextual_message = f"[Sistem: Ingat, user_id pengguna yang sedang ngobrol denganmu saat ini adalah {user_id}].\n\n{message}"
    history.append(HumanMessage(content=contextual_message))
    
    # Eksekusi AI Agent 
    response = agent.invoke({"messages": history})
    
    # Ekstrak balasan AI
    raw_content = response["messages"][-1].content
    if isinstance(raw_content, list):
        ai_reply = " ".join([item.get("text", "") for item in raw_content if isinstance(item, dict) and "text" in item])
    else:
        ai_reply = str(raw_content)
        
    # Simpan balasan AI ke history
    history.append(AIMessage(content=ai_reply))
    
    return ai_reply
