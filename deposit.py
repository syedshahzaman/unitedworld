from flask import Blueprint, render_template, request, session, jsonify
import os
import time
import uuid

# -------------------------
# BLUEPRINT
# -------------------------
deposit_bp = Blueprint("deposit", __name__)

# -------------------------
# FILE PATHS
# -------------------------
DATA_DIR = "data"
DEPOSIT_FILE = os.path.join(DATA_DIR, "deposit_request.tsv")
USERS_FILE = os.path.join(DATA_DIR, "users.tsv")

DEPOSIT_HEADER = (
    "request_id\tuser_email\tamount\ttransaction_id\t"
    "phone\tpayment_method\tstatus\tcreated_at\n"
)

# -------------------------
# FILE INIT
# -------------------------
def ensure_deposit_file():
    os.makedirs(DATA_DIR, exist_ok=True)
    if not os.path.exists(DEPOSIT_FILE):
        with open(DEPOSIT_FILE, "w") as f:
            f.write(DEPOSIT_HEADER)

# -------------------------
# HELPERS
# -------------------------
def get_logged_in_user():
    return session.get("user")

def save_deposit_request(user_email, amount, txn_id, phone, method):
    ensure_deposit_file()

    request_id = f"DPR{int(time.time())}{uuid.uuid4().hex[:6].upper()}"
    created_at = int(time.time())

    with open(DEPOSIT_FILE, "a") as f:
        f.write(
            f"{request_id}\t{user_email}\t{amount}\t{txn_id}\t"
            f"{phone}\t{method}\tpending\t{created_at}\n"
        )

    return request_id

def get_user_deposits(user_email, limit=10):
    ensure_deposit_file()
    deposits = []

    with open(DEPOSIT_FILE, "r") as f:
        lines = f.readlines()

    for i, line in enumerate(lines):
        if i == 0:
            continue
        cols = line.strip().split("\t")
        if len(cols) >= 8 and cols[1] == user_email:
            deposits.append({
                "request_id": cols[0],
                "amount": float(cols[2]),
                "transaction_id": cols[3],
                "phone": cols[4],
                "payment_method": cols[5],
                "status": cols[6],
                "created_at": int(cols[7])
            })

    deposits.sort(key=lambda x: x["created_at"], reverse=True)
    return deposits[:limit]

# -------------------------
# ROUTES
# -------------------------

# 1️⃣ Deposit Page
@deposit_bp.route("/deposit")
def deposit_page():
    if "user" not in session:
        return render_template("login.html")

    return render_template("deposit.html")

# 2️⃣ Create Deposit Request API
@deposit_bp.route("/api/deposit-request", methods=["POST"])
def deposit_request():
    if "user" not in session:
        return jsonify({"success": False, "error": "Not logged in"}), 401

    data = request.get_json()
    if not data:
        return jsonify({"success": False, "error": "Invalid request"}), 400

    amount = float(data.get("amount", 0))
    txn_id = data.get("transaction_id", "").strip()
    phone = data.get("phone", "").strip()
    method = data.get("payment_method", "upi")

    # 🔒 Validations
    if amount < 500 or amount > 100000:
        return jsonify({"success": False, "error": "Invalid deposit amount"}), 400

    if len(txn_id) < 5:
        return jsonify({"success": False, "error": "Invalid transaction ID"}), 400

    if not phone.isdigit() or len(phone) != 10:
        return jsonify({"success": False, "error": "Invalid phone number"}), 400

    user_email = session["user"]

    request_id = save_deposit_request(
        user_email=user_email,
        amount=amount,
        txn_id=txn_id,
        phone=phone,
        method=method
    )

    return jsonify({
        "success": True,
        "message": "Deposit request submitted successfully",
        "request_id": request_id,
        "status": "pending"
    })

# 3️⃣ User Deposit History API
@deposit_bp.route("/api/deposit-history")
def deposit_history():
    if "user" not in session:
        return jsonify({"success": False, "error": "Not logged in"}), 401

    deposits = get_user_deposits(session["user"])

    return jsonify({
        "success": True,
        "deposits": deposits,
        "count": len(deposits)
    })
