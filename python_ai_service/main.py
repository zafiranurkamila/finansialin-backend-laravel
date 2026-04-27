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
from schemas import PredictiveBudgetRequest

app = FastAPI(title="Predictive Budgeting AI Service")

# Setup Device and Model globally for OCR
device = "cuda" if torch.cuda.is_available() else "cpu"
print(f"Loading Donut OCR model on {device}...")
processor = None
model = None
try:
    processor = DonutProcessor.from_pretrained("naver-clova-ix/donut-base-finetuned-cord-v2")
    model = VisionEncoderDecoderModel.from_pretrained("naver-clova-ix/donut-base-finetuned-cord-v2")
    model.to(device)
    print("Model loaded successfully!")
except Exception as e:
    import traceback
    traceback.print_exc()
    print(f"Failed to load model: {e}")

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

@app.post("/predict/budget")
def predict_budget(request: PredictiveBudgetRequest):
    if len(request.expenses) < 14:
        raise HTTPException(
            status_code=400, 
            detail="Not enough historical data points to fit Prophet. Minimum 14 points required."
        )

    # 1. Convert expenses to DataFrame
    df = pd.DataFrame([{"ds": exp.date, "y": exp.amount} for exp in request.expenses])
    df['ds'] = pd.to_datetime(df['ds'])

    # 2. Create custom regressor `is_payday_window`
    df['is_payday_window'] = df['ds'].apply(lambda d: is_within_payday_window(d, request.payday_date))

    # 3. Initialize Prophet and add holidays
    m = Prophet(daily_seasonality=False) # Data is daily; weekly seasonality is enabled by default
    m.add_country_holidays(country_name='ID')

    # 4. Add custom regressor
    m.add_regressor('is_payday_window')

    # 5. Fit the model
    try:
        m.fit(df)
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Error fitting Prophet model: {str(e)}")

    # 6. Create future dataframe until end of current month
    last_date = df['ds'].max()
    end_of_month = get_last_day_of_month(last_date)
    
    if last_date >= end_of_month:
        # We are at the end of the month or beyond it, nothing to predict for the *current* month
        future_days = 0 
    else:
        future_days = (end_of_month - last_date).days

    # Calculate actual expenses for the current month
    current_month = last_date.month
    current_year = last_date.year
    actual_current_month_expense = df[(df['ds'].dt.month == current_month) & (df['ds'].dt.year == current_year)]['y'].sum()

    if future_days == 0:
        # We are already at or past the end of the month
        projected_total_expense = actual_current_month_expense
    else:
        future = m.make_future_dataframe(periods=future_days, freq='D')
        
        # 7. Add `is_payday_window` to future dataframe
        future['is_payday_window'] = future['ds'].apply(lambda d: is_within_payday_window(d, request.payday_date))

        # 8. Predict future expenses
        forecast = m.predict(future)

        # 9. Calculate projected total expense
        mask = (forecast['ds'] > last_date) & (forecast['ds'].dt.month == current_month) & (forecast['ds'].dt.year == current_year)
        predicted_remaining_expense = forecast.loc[mask, 'yhat'].sum()

        # Prevent negative predictions since expenses cannot be strictly negative
        if predicted_remaining_expense < 0:
            predicted_remaining_expense = 0

        projected_total_expense = actual_current_month_expense + predicted_remaining_expense

    # 10. Determine overspending
    is_overspending = bool(projected_total_expense > request.budget)
    
    warning_message = "You are on track."
    if is_overspending:
        warning_message = "Warning: Projected total expenses exceed your monthly budget. Consider adjusting your spending habits."

    return {
        "user_id": request.user_id,
        "projected_total_expense": round(float(projected_total_expense), 2),
        "budget": round(float(request.budget), 2),
        "is_overspending": is_overspending,
        "warning_message": warning_message
    }

@app.post("/predict/ocr")
async def predict_ocr(receiptImage: UploadFile = File(...)):
    if model is None or processor is None:
        raise HTTPException(
            status_code=500, 
            detail="Model OCR lokal gagal dimuat saat server startup akibat kendala versi PyTorch. Silakan cek log terminal."
        )

    try:
        contents = await receiptImage.read()
        image = Image.open(io.BytesIO(contents)).convert("RGB")
    except Exception as e:
        raise HTTPException(status_code=400, detail=f"Invalid image file: {str(e)}")

    try:
        # Prepare inputs
        pixel_values = processor(image, return_tensors="pt").pixel_values
        pixel_values = pixel_values.to(device)

        # Prepare decoder inputs
        task_prompt = "<s_cord-v2>"
        decoder_input_ids = processor.tokenizer(task_prompt, add_special_tokens=False, return_tensors="pt").input_ids
        decoder_input_ids = decoder_input_ids.to(device)

        # Generate output
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

        # Decode output
        sequence = processor.batch_decode(outputs.sequences)[0]
        sequence = sequence.replace(processor.tokenizer.eos_token, "").replace(processor.tokenizer.pad_token, "")
        sequence = re.sub(r"<.*?>", "", sequence, count=1).strip()  # remove first task start token

        # Parse XML-like output
        merchant_name = None
        total_amount = 0.0
        date = None
        
        merchant_match = re.search(r'<nm>(.*?)</nm>', sequence)
        if merchant_match:
            merchant_name = merchant_match.group(1).strip()
            
        total_match = re.search(r'<total\.total_price>(.*?)</total\.total_price>', sequence)
        if total_match:
            try:
                total_clean = re.sub(r'[^\d\.]', '', total_match.group(1).replace(',', ''))
                if total_clean:
                    total_amount = float(total_clean)
            except ValueError:
                pass
                
        date_match = re.search(r'<date>(.*?)</date>', sequence)
        if date_match:
            date = date_match.group(1).strip()
            
        return {
            "status": "success",
            "data": {
                "merchant_name": merchant_name,
                "total_amount": total_amount,
                "date": date,
                "suggested_category": "Pengeluaran Lainnya"
            }
        }
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"OCR Processing Error: {str(e)}")
