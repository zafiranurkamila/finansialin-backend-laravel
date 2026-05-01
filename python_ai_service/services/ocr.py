import re
from PIL import Image

def extract_receipt_data(image: Image.Image, processor, model, device) -> dict:
    """
    Memproses gambar struk dan mengekstrak data JSON menggunakan model Donut.
    """
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
    print("--- AI RAW OUTPUT ---")
    print(parsed_data)

    # 4. Ekstraksi Data dengan Fallback
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
        # 1. Cari Nama Merchant di store_info
        store_info = parsed_data.get("store_info", {})
        merchant_name = get_value(store_info, ["name", "nm", "store_name"])

        # 2. Cari Tanggal di payment_info
        payment_info = parsed_data.get("payment_info", {})
        date = get_value(payment_info, ["date", "dt"])

        # 3. FALLBACK: Jika merchant atau tanggal masih kosong, cari di dalam "menu"
        menu_items = parsed_data.get("menu", [])
        if isinstance(menu_items, list) and len(menu_items) > 0:
            if not merchant_name:
                first_item_name = str(menu_items[0].get("nm", ""))
                if first_item_name and not re.search(r'\d{4}-\d{2}-\d{2}', first_item_name):
                    merchant_name = first_item_name.strip()

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

        # 4. Cari Total
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
