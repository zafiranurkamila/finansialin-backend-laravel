from pydantic import BaseModel
from typing import List

class ExpenseRecord(BaseModel):
    date: str
    amount: float

class PredictiveBudgetRequest(BaseModel):
    user_id: int
    budget: float
    payday_date: int
    expenses: List[ExpenseRecord]

class ChatRequest(BaseModel):
    user_id: int
    session_id: str
    message: str