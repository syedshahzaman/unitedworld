from flask import Flask, render_template, request, redirect, url_for, session, jsonify, make_response
import uuid
import time
import os
import re
from datetime import datetime, date

# -------------------------
# APP CONFIG                                                                              # -------------------------
app = Flask(__name__)
app.secret_key = "unitedworld-secret-key"

DATA_DIR = "data"
USERS_FILE = os.path.join(DATA_DIR, "users.tsv")
TXN_FILE = os.path.join(DATA_DIR, "transactions.tsv")
MARKET_FILE = os.path.join(DATA_DIR, "market.tsv")
WITHDRAW_REQUEST_FILE = os.path.join(DATA_DIR, "withdraw_request.tsv")
DEPOSIT_REQUEST_FILE = os.path.join(DATA_DIR, "deposit_request.tsv")
ADMIN_LOG_FILE = os.path.join(DATA_DIR, "admin_log.tsv")
TAX_COLLECTION_FILE = os.path.join(DATA_DIR, "tax_collection.tsv")
ORDERS_FILE = os.path.join(DATA_DIR, "orders.tsv")
INTERNAL_MRX_FILE = os.path.join(DATA_DIR, "internal_mrx.tsv")
DAILY_TRADES_FILE = os.path.join(DATA_DIR, "daily_trades.tsv")  # NEW

# -------------------------
# FILE HEADERS
# -------------------------
USERS_HEADER = (
    "user_id\tfull_name\temail\tmobile\tpassword\treferral\t"
    "inr_balance\tmrx_balance\tcreated_at\n"
)

TXN_HEADER = (
    "txn_id\tuser_email\ttype\tamount_inr\tamount_mrx\tprice\ttimestamp\tstatus\n"
)

MARKET_HEADER = "inr_pool\tmrx_pool\tlast_updated\n"

WITHDRAW_REQUEST_HEADER = (
    "request_id\tuser_email\tuser_name\tamount\tstatus\t"
    "bank_name\taccount_number\tifsc_code\t"
    "created_at\tprocessed_at\tremarks\n"
)

DEPOSIT_REQUEST_HEADER = (
    "request_id\tuser_email\tamount\ttransaction_id\t"
    "phone\tpayment_method\tstatus\tcreated_at\n"
)

ADMIN_LOG_HEADER = (
    "log_id\tadmin_email\taction\ttarget_id\ttarget_type\t"
    "details\ttimestamp\tip_address\n"
)

TAX_COLLECTION_HEADER = (
    "tax_id\tuser_email\tuser_name\torder_type\t"
    "order_amount\ttax_amount\torder_worth\t"
    "order_date\ttimestamp\tremarks\n"
)

ORDERS_HEADER = (
    "order_id\tuser_email\tuser_name\torder_type\torder_amount_inr\t"
    "order_amount_mrx\tprice_at_order\ttax_amount\tstatus\tcreated_at\tremarks\n"
)

INTERNAL_MRX_HEADER = (
    "user_email\tinternal_mrx_balance\tlast_updated\n"
)

# NEW: Daily trades tracking
DAILY_TRADES_HEADER = (
    "date\tuser_email\ttotal_amount\ttransaction_count\tlast_updated\n"
)

# -------------------------
# CONSTANTS
# -------------------------
PRICE_FLOOR = 1.0  # Minimum ₹1 per MRX - CANNOT GO BELOW THIS
TAX_RATE = 0.05  # 5% tax ONLY on BUY orders (NO tax on sell/withdraw)
MAX_SINGLE_ORDER = 1000.00  # Maximum ₹1000 per single order
DAILY_TRADING_LIMIT = 10000.00  # NEW: ₹10,000 daily limit per user
MIN_INR_POOL = 1000.00  # NEW: Minimum INR pool balance

# -------------------------
# FILE INITIALIZATION
# -------------------------
def ensure_files():
    os.makedirs(DATA_DIR, exist_ok=True)

    if not os.path.exists(USERS_FILE):
        with open(USERS_FILE, "w") as f:
            f.write(USERS_HEADER)
            f.write(f"{str(uuid.uuid4())[:8]}\tJohn Doe\tjohn@example.com\t"
                   f"1234567890\tpassword123\tREF001\t"
                   f"150000\t0\t{int(time.time())}\n")
            f.write(f"ADMIN001\tAdmin Wilson\tadmin@unitedworld.com\t"
                   f"9876543210\tadmin123\tADMIN001\t"
                   f"1000000\t0\t{int(time.time())}\n")

    if not os.path.exists(TXN_FILE):
        with open(TXN_FILE, "w") as f:
            f.write(TXN_HEADER)

    if not os.path.exists(MARKET_FILE):
        with open(MARKET_FILE, "w") as f:
            f.write(MARKET_HEADER)
            f.write("2000.00\t1000.000000\t0\n")

    if not os.path.exists(WITHDRAW_REQUEST_FILE):
        with open(WITHDRAW_REQUEST_FILE, "w") as f:
            f.write(WITHDRAW_REQUEST_HEADER)

    if not os.path.exists(DEPOSIT_REQUEST_FILE):
        with open(DEPOSIT_REQUEST_FILE, "w") as f:
            f.write(DEPOSIT_REQUEST_HEADER)

    if not os.path.exists(ADMIN_LOG_FILE):
        with open(ADMIN_LOG_FILE, "w") as f:
            f.write(ADMIN_LOG_HEADER)

    if not os.path.exists(TAX_COLLECTION_FILE):
        with open(TAX_COLLECTION_FILE, "w") as f:
            f.write(TAX_COLLECTION_HEADER)

    if not os.path.exists(ORDERS_FILE):
        with open(ORDERS_FILE, "w") as f:
            f.write(ORDERS_HEADER)

    if not os.path.exists(INTERNAL_MRX_FILE):
        with open(INTERNAL_MRX_FILE, "w") as f:
            f.write(INTERNAL_MRX_HEADER)

    # NEW: Initialize daily trades file
    if not os.path.exists(DAILY_TRADES_FILE):
        with open(DAILY_TRADES_FILE, "w") as f:
            f.write(DAILY_TRADES_HEADER)

# -------------------------
# DAILY TRADING LIMIT HELPERS (NEW)
# -------------------------
def get_user_daily_trades(user_email):
    """Get user's total trades for today"""
    ensure_files()

    today = date.today().isoformat()
    daily_total = 0.0

    if not os.path.exists(DAILY_TRADES_FILE):
        return 0.0

    with open(DAILY_TRADES_FILE, "r") as f:
        lines = f.readlines()

    for i, line in enumerate(lines):
        if i == 0:
            continue
        cols = line.strip().split("\t")
        if len(cols) >= 3 and cols[0] == today and cols[1] == user_email:
            daily_total = float(cols[2]) if cols[2] else 0.0
            break

    return daily_total

def update_user_daily_trades(user_email, amount):
    """Update user's daily trade total"""
    ensure_files()

    today = date.today().isoformat()
    rows = []
    updated = False

    if os.path.exists(DAILY_TRADES_FILE):
        with open(DAILY_TRADES_FILE, "r") as f:
            rows = f.readlines()

    with open(DAILY_TRADES_FILE, "w") as f:
        if rows and rows[0].startswith("date"):
            f.write(rows[0])
        else:
            f.write(DAILY_TRADES_HEADER)

        for line in rows[1:]:
            if not line.strip():
                continue
            cols = line.strip().split("\t")
            if len(cols) >= 3 and cols[0] == today and cols[1] == user_email:
                # Update existing record
                current_total = float(cols[2]) if cols[2] else 0.0
                current_count = int(cols[3]) if len(cols) > 3 and cols[3] else 0
                new_total = current_total + amount
                new_count = current_count + 1

                cols[2] = str(round(new_total, 2))
                cols[3] = str(new_count) if len(cols) > 3 else str(new_count)
                if len(cols) > 4:
                    cols[4] = str(int(time.time()))
                else:
                    cols.append(str(int(time.time())))

                f.write("\t".join(cols) + "\n")
                updated = True
            else:
                f.write(line)

        if not updated:
            # Create new record for today
            new_total = amount
            f.write(f"{today}\t{user_email}\t{round(new_total, 2)}\t1\t{int(time.time())}\n")

    return new_total if updated else amount

def check_daily_trading_limit(user_email, amount):
    """Check if user has exceeded daily trading limit"""
    daily_total = get_user_daily_trades(user_email)
    new_total = daily_total + amount

    if new_total > DAILY_TRADING_LIMIT:
        remaining = DAILY_TRADING_LIMIT - daily_total
        return False, f"Daily trading limit exceeded. Limit: ₹{DAILY_TRADING_LIMIT:.2f}, Used: ₹{daily_total:.2f}, Remaining: ₹{remaining:.2f}"

    return True, f"Daily usage: ₹{daily_total:.2f}/{DAILY_TRADING_LIMIT:.2f}"

# -------------------------
# INTERNAL MRX BALANCE HELPERS
# -------------------------
def get_internal_mrx_balance(user_email):
    """Get user's internal MRX balance (hidden from user)"""
    ensure_files()

    if not os.path.exists(INTERNAL_MRX_FILE):
        return 0.0

    with open(INTERNAL_MRX_FILE, "r") as f:
        lines = f.readlines()

    for i, line in enumerate(lines):
        if i == 0:
            continue
        cols = line.strip().split("\t")
        if len(cols) >= 2 and cols[0] == user_email:
            return float(cols[1]) if cols[1] else 0.0

    return 0.0

def update_internal_mrx_balance(user_email, new_mrx_balance):
    """Update user's internal MRX balance"""
    ensure_files()

    rows = []
    updated = False

    if os.path.exists(INTERNAL_MRX_FILE):
        with open(INTERNAL_MRX_FILE, "r") as f:
            rows = f.readlines()

    with open(INTERNAL_MRX_FILE, "w") as f:
        if rows and rows[0].startswith("user_email"):
            f.write(rows[0])
        else:
            f.write(INTERNAL_MRX_HEADER)

        for line in rows[1:]:
            if not line.strip():
                continue
            cols = line.strip().split("\t")
            if len(cols) >= 2 and cols[0] == user_email:
                cols[1] = str(round(new_mrx_balance, 6))
                cols[2] = str(int(time.time())) if len(cols) > 2 else str(int(time.time()))
                f.write("\t".join(cols) + "\n")
                updated = True
            else:
                f.write(line)

        if not updated:
            f.write(f"{user_email}\t{round(new_mrx_balance, 6)}\t{int(time.time())}\n")

    return new_mrx_balance

def get_total_internal_mrx():
    """Get total internal MRX across all users"""
    ensure_files()

    if not os.path.exists(INTERNAL_MRX_FILE):
        return 0.0

    total = 0.0
    with open(INTERNAL_MRX_FILE, "r") as f:
        lines = f.readlines()

    for i, line in enumerate(lines):
        if i == 0:
            continue
        cols = line.strip().split("\t")
        if len(cols) >= 2 and cols[1]:
            try:
                total += float(cols[1])
            except:
                continue

    return total

# -------------------------
# TAX COLLECTION HELPERS
# -------------------------
def log_tax_collection(user_email, user_name, order_type, order_amount, tax_amount, order_worth, remarks=""):
    """Log tax collection to separate file"""
    ensure_files()

    tax_id = f"TAX{int(time.time())}{uuid.uuid4().hex[:6].upper()}"
    timestamp = int(time.time())
    order_date = datetime.fromtimestamp(timestamp).strftime('%Y-%m-%d %H:%M:%S')

    with open(TAX_COLLECTION_FILE, "a") as f:
        f.write(
            f"{tax_id}\t{user_email}\t{user_name}\t{order_type}\t"
            f"{order_amount}\t{tax_amount}\t{order_worth}\t"
            f"{order_date}\t{timestamp}\t{remarks}\n"
        )

    return tax_id

def get_tax_collection_stats():
    """Get tax collection statistics"""
    ensure_files()

    if not os.path.exists(TAX_COLLECTION_FILE):
        return {
            'total_tax': 0,
            'total_transactions': 0,
            'buy_tax': 0,
            'withdrawal_tax': 0,
            'sell_tax': 0,
            'deposit_tax': 0
        }

    total_tax = 0
    total_transactions = 0
    buy_tax = 0
    withdrawal_tax = 0
    sell_tax = 0
    deposit_tax = 0

    with open(TAX_COLLECTION_FILE, "r") as f:
        lines = f.readlines()

    for i, line in enumerate(lines):
        if i == 0:
            continue

        line = line.strip()
        if not line:
            continue

        cols = line.split("\t")
        if len(cols) >= 9:
            total_transactions += 1
            tax_amount = float(cols[5]) if cols[5] else 0
            total_tax += tax_amount

            order_type = cols[3] if len(cols) > 3 else ""
            if order_type == "buy":
                buy_tax += tax_amount
            elif order_type == "withdrawal":
                withdrawal_tax += tax_amount
            elif order_type == "sell":
                sell_tax += tax_amount
            elif order_type == "deposit":
                deposit_tax += tax_amount

    return {
        'total_tax': round(total_tax, 2),
        'total_transactions': total_transactions,
        'buy_tax': round(buy_tax, 2),
        'withdrawal_tax': round(withdrawal_tax, 2),
        'sell_tax': round(sell_tax, 2),
        'deposit_tax': round(deposit_tax, 2)
    }

def get_tax_collection_records(limit=100):
    """Get recent tax collection records"""
    ensure_files()

    records = []

    if not os.path.exists(TAX_COLLECTION_FILE):
        return records

    with open(TAX_COLLECTION_FILE, "r") as f:
        lines = f.readlines()

    for i, line in enumerate(lines):
        if i == 0:
            continue

        line = line.strip()
        if not line:
            continue

        cols = line.split("\t")
        if len(cols) >= 9:
            records.append({
                'tax_id': cols[0],
                'user_email': cols[1],
                'user_name': cols[2],
                'order_type': cols[3],
                'order_amount': float(cols[4]) if cols[4] else 0,
                'tax_amount': float(cols[5]) if cols[5] else 0,
                'order_worth': float(cols[6]) if cols[6] else 0,
                'order_date': cols[7],
                'timestamp': int(cols[8]) if cols[8] else 0,
                'remarks': cols[9] if len(cols) > 9 else ""
            })

    records.sort(key=lambda x: x['timestamp'], reverse=True)
    return records[:limit]

# -------------------------
# ORDER TRACKING HELPERS
# -------------------------
def save_order_record(user_email, user_name, order_type, order_amount_inr,
                     order_amount_mrx, price_at_order, tax_amount, remarks=""):
    """Save order details"""
    ensure_files()

    order_id = f"ORD{int(time.time())}{uuid.uuid4().hex[:6].upper()}"
    created_at = int(time.time())

    with open(ORDERS_FILE, "a") as f:
        f.write(
            f"{order_id}\t{user_email}\t{user_name}\t{order_type}\t"
            f"{order_amount_inr}\t{order_amount_mrx}\t{price_at_order}\t"
            f"{tax_amount}\tcompleted\t{created_at}\t{remarks}\n"
        )

    return order_id

# -------------------------
# MARKET HELPERS WITH PRICE FLOOR VALIDATION
# -------------------------
def read_market():
    with open(MARKET_FILE, "r") as f:
        lines = f.readlines()

    if len(lines) < 2:
        return 2000.0, 1000.0

    cols = lines[1].strip().split("\t")
    return float(cols[0]), float(cols[1])

def write_market(inr_pool, mrx_pool):
    """Write market state with price floor validation"""
    if mrx_pool > 0:
        new_price = inr_pool / mrx_pool
        if new_price < PRICE_FLOOR:
            raise ValueError(f"Cannot set market: Price would be ₹{new_price:.4f} which is below ₹{PRICE_FLOOR:.2f} floor")

    with open(MARKET_FILE, "w") as f:
        f.write(MARKET_HEADER)
        f.write(f"{round(inr_pool,2)}\t{round(mrx_pool,6)}\t{int(time.time())}\n")

def validate_price_floor(new_inr_pool, new_mrx_pool):
    """Validate that new pool state maintains minimum price"""
    if new_mrx_pool <= 0:
        return False, "MRX pool cannot be zero or negative"

    new_price = new_inr_pool / new_mrx_pool
    if new_price < PRICE_FLOOR:
        return False, f"Price would drop below ₹{PRICE_FLOOR:.2f} floor"

    return True, f"Price: ₹{new_price:.4f}"

# -------------------------
# USER HELPERS
# -------------------------
def get_user(email):
    with open(USERS_FILE, "r") as f:
        for line in f:
            if line.startswith("user_id"):
                continue
            cols = line.strip().split("\t")
            if cols[2] == email:
                return cols
    return None

def get_all_users():
    users = []
    with open(USERS_FILE, "r") as f:
        lines = f.readlines()
        for i, line in enumerate(lines):
            if i == 0:
                continue
            cols = line.strip().split("\t")
            if len(cols) >= 8:
                users.append({
                    'user_id': cols[0],
                    'full_name': cols[1],
                    'email': cols[2],
                    'mobile': cols[3],
                    'inr_balance': float(cols[6]),
                    'mrx_balance': 0.0,
                    'created_at': int(cols[8])
                })
    return users

def update_user_balances(email, new_inr, new_mrx):
    """Update user balances - MRX always 0"""
    rows = []
    with open(USERS_FILE, "r") as f:
        rows = f.readlines()

    with open(USERS_FILE, "w") as f:
        for line in rows:
            if line.startswith("user_id"):
                f.write(line)
                continue

            cols = line.strip().split("\t")
            if cols[2] == email:
                cols[6] = str(round(new_inr, 2))
                cols[7] = "0"
                f.write("\t".join(cols) + "\n")
            else:
                f.write(line)

def is_admin(email):
    """Check if user is admin"""
    user = get_user(email)
    if not user:
        return False
    return 'admin' in email.lower() or user[0].startswith('ADM')

# -------------------------
# ADMIN LOGGING
# -------------------------
def log_admin_action(admin_email, action, target_id, target_type, details=""):
    """Log admin actions for audit trail"""
    ensure_files()

    log_id = f"LOG{int(time.time())}{uuid.uuid4().hex[:6].upper()}"
    timestamp = int(time.time())
    ip_address = request.remote_addr if request else "0.0.0.0"

    with open(ADMIN_LOG_FILE, "a") as f:
        f.write(
            f"{log_id}\t{admin_email}\t{action}\t{target_id}\t{target_type}\t"
            f"{details}\t{timestamp}\t{ip_address}\n"
        )

    return log_id

def get_recent_admin_logs(limit=50):
    """Get recent admin logs"""
    ensure_files()
    logs = []

    if not os.path.exists(ADMIN_LOG_FILE):
        return logs

    with open(ADMIN_LOG_FILE, "r") as f:
        lines = f.readlines()

    for i, line in enumerate(lines):
        if i == 0:
            continue
        cols = line.strip().split("\t")
        if len(cols) >= 8:
            logs.append({
                'log_id': cols[0],
                'admin_email': cols[1],
                'action': cols[2],
                'target_id': cols[3],
                'target_type': cols[4],
                'details': cols[5],
                'timestamp': int(cols[6]),
                'ip_address': cols[7]
            })

    logs.sort(key=lambda x: x['timestamp'], reverse=True)
    return logs[:limit]

# -------------------------
# DEPOSIT REQUEST HELPERS
# -------------------------
def save_deposit_request(user_email, amount, txn_id, phone, method):
    ensure_files()

    request_id = f"DPR{int(time.time())}{uuid.uuid4().hex[:6].upper()}"
    created_at = int(time.time())

    with open(DEPOSIT_REQUEST_FILE, "a") as f:
        f.write(
            f"{request_id}\t{user_email}\t{amount}\t{txn_id}\t"
            f"{phone}\t{method}\tpending\t{created_at}\n"
        )

    return request_id

def get_user_deposit_requests(user_email, limit=10):
    ensure_files()
    deposits = []

    if not os.path.exists(DEPOSIT_REQUEST_FILE):
        return deposits

    with open(DEPOSIT_REQUEST_FILE, "r") as f:
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

def get_all_deposit_requests(limit=50):
    ensure_files()
    deposits = []

    if not os.path.exists(DEPOSIT_REQUEST_FILE):
        return deposits

    with open(DEPOSIT_REQUEST_FILE, "r") as f:
        lines = f.readlines()

    for i, line in enumerate(lines):
        if i == 0:
            continue
        cols = line.strip().split("\t")
        if len(cols) >= 8:
            deposits.append({
                "request_id": cols[0],
                "user_email": cols[1],
                "amount": float(cols[2]),
                "transaction_id": cols[3],
                "phone": cols[4],
                "payment_method": cols[5],
                "status": cols[6],
                "created_at": int(cols[7])
            })

    deposits.sort(key=lambda x: x["created_at"], reverse=True)
    return deposits[:limit]

def update_deposit_request_status(request_id, status, admin_email=""):
    ensure_files()

    if not os.path.exists(DEPOSIT_REQUEST_FILE):
        return False

    rows = []
    updated = False
    deposit_info = None

    with open(DEPOSIT_REQUEST_FILE, "r") as f:
        rows = f.readlines()

    with open(DEPOSIT_REQUEST_FILE, "w") as f:
        for line in rows:
            if line.startswith("request_id"):
                f.write(line)
                continue

            cols = line.strip().split("\t")
            if cols[0] == request_id:
                if status == "approved" and cols[6] != "approved":
                    deposit_info = {
                        "user_email": cols[1],
                        "amount": float(cols[2])
                    }

                cols[6] = status
                updated = True
                f.write("\t".join(cols) + "\n")
            else:
                f.write(line)

    if updated and status == "approved" and deposit_info:
        user = get_user(deposit_info["user_email"])
        if user:
            current_inr = float(user[6])
            new_inr = current_inr + deposit_info["amount"]
            update_user_balances(deposit_info["user_email"], new_inr, 0)

            save_transaction(
                deposit_info["user_email"],
                'deposit_approved',
                deposit_info["amount"],
                0,
                0
            )

            if admin_email:
                log_admin_action(
                    admin_email,
                    "deposit_approved",
                    request_id,
                    "deposit_request",
                    f"Approved deposit of ₹{deposit_info['amount']} for {deposit_info['user_email']}"
                )

    return updated

# -------------------------
# TRANSACTION HELPERS
# -------------------------
def save_transaction(user_email, txn_type, amount_inr, amount_mrx, price):
    txn_id = f"TXN{int(time.time())}{uuid.uuid4().hex[:6].upper()}"
    timestamp = int(time.time())

    with open(TXN_FILE, "a") as f:
        f.write(
            f"{txn_id}\t{user_email}\t{txn_type}\t"
            f"{amount_inr}\t{round(amount_mrx,6)}\t"
            f"{round(price,4)}\t{timestamp}\tcompleted\n"
        )
    return txn_id

def get_user_transactions(user_email, limit=50):
    transactions = []
    with open(TXN_FILE, "r") as f:
        lines = f.readlines()
        for i, line in enumerate(lines):
            if i == 0:
                continue
            cols = line.strip().split("\t")
            if len(cols) >= 8 and cols[1] == user_email:
                transactions.append({
                    'txn_id': cols[0],
                    'type': cols[2],
                    'amount_inr': float(cols[3]),
                    'amount_mrx': float(cols[4]),
                    'price': float(cols[5]),
                    'timestamp': int(cols[6]),
                    'status': cols[7]
                })

    transactions.sort(key=lambda x: x['timestamp'], reverse=True)
    return transactions[:limit]

def get_recent_transactions(limit=50):
    """Get recent transactions across all users"""
    transactions = []
    with open(TXN_FILE, "r") as f:
        lines = f.readlines()
        for i, line in enumerate(lines):
            if i == 0:
                continue
            cols = line.strip().split("\t")
            if len(cols) >= 8:
                transactions.append({
                    'txn_id': cols[0],
                    'user_email': cols[1],
                    'type': cols[2],
                    'amount_inr': float(cols[3]),
                    'amount_mrx': float(cols[4]),
                    'price': float(cols[5]),
                    'timestamp': int(cols[6]),
                    'status': cols[7]
                })

    transactions.sort(key=lambda x: x['timestamp'], reverse=True)
    return transactions[:limit]

# -------------------------
# WITHDRAWAL REQUEST HELPERS - UPDATED WITH INR POOL PROTECTION
# -------------------------
def save_withdraw_request(user_email, user_name, amount, bank_name, account_number, ifsc_code):
    """Save withdrawal request and DEDUCT amount immediately"""
    # Check if withdrawal would bring INR pool below MIN_INR_POOL (1000)
    inr_pool, mrx_pool = read_market()
    new_inr_pool = inr_pool - amount

    if new_inr_pool < MIN_INR_POOL:
        # Don't even allow the request to be created
        print(f"WITHDRAWAL BLOCKED: Request would bring INR pool below ₹{MIN_INR_POOL:.2f}. Current: ₹{inr_pool:.2f}, Requested: ₹{amount:.2f}")
        return None

    request_id = f"WDR{int(time.time())}{uuid.uuid4().hex[:6].upper()}"
    created_at = int(time.time())

    line = f"{request_id}\t{user_email}\t{user_name}\t{amount}\tpending\t{bank_name}\t{account_number}\t{ifsc_code}\t{created_at}\t0\t\n"

    with open(WITHDRAW_REQUEST_FILE, "a") as f:
        f.write(line)

    # NEW: Deduct amount from user wallet immediately
    user = get_user(user_email)
    if user:
        current_inr = float(user[6])
        if current_inr >= amount:
            new_inr = current_inr - amount
            update_user_balances(user_email, new_inr, 0)

            # Log the deduction transaction
            save_transaction(
                user_email,
                'withdrawal_requested',
                -amount,
                0,
                0
            )

            print(f"WITHDRAWAL REQUESTED: Deducted ₹{amount} from {user_email}. Balance: ₹{new_inr:.2f}")
        else:
            print(f"ERROR: Insufficient balance for withdrawal request. User: {user_email}, Amount: {amount}, Balance: {current_inr}")
            return None

    return request_id

def get_user_withdrawal_requests(user_email):
    """Get withdrawal requests for a specific user"""
    requests = []
    if not os.path.exists(WITHDRAW_REQUEST_FILE):
        return requests

    with open(WITHDRAW_REQUEST_FILE, "r") as f:
        lines = f.readlines()

    for i, line in enumerate(lines):
        if i == 0:
            continue

        line = line.strip()
        if not line:
            continue

        cols = line.split("\t")

        if len(cols) >= 8 and cols[1] == user_email:
            while len(cols) < 11:
                cols.append("")

            try:
                amount = float(cols[3]) if cols[3] else 0
            except:
                amount = 0

            created_at_str = re.sub(r'[^0-9]', '', str(cols[8])) if len(cols) > 8 else ""
            try:
                created_at = int(created_at_str) if created_at_str else int(time.time())
            except:
                created_at = int(time.time())

            account_num = cols[6] if len(cols) > 6 else ""
            last_four = account_num[-4:] if account_num and len(account_num) >= 4 else ""

            requests.append({
                'request_id': cols[0],
                'amount': amount,
                'status': cols[4] if len(cols) > 4 else 'pending',
                'bank_name': cols[5] if len(cols) > 5 else '',
                'account_number': last_four,
                'ifsc_code': cols[7] if len(cols) > 7 else '',
                'created_at': created_at,
                'processed_at': int(cols[9]) if len(cols) > 9 and cols[9].isdigit() else None,
                'remarks': cols[10] if len(cols) > 10 else ""
            })

    requests.sort(key=lambda x: x['created_at'], reverse=True)
    return requests

def get_all_withdrawal_requests(limit=1000):
    """Get all withdrawal requests (for admin use)"""
    ensure_files()
    requests = []

    if not os.path.exists(WITHDRAW_REQUEST_FILE):
        return requests

    with open(WITHDRAW_REQUEST_FILE, "r") as f:
        lines = f.readlines()

    for i, line in enumerate(lines):
        if i == 0:
            continue

        line = line.strip()
        if not line:
            continue

        cols = line.split("\t")

        if len(cols) >= 8:
            while len(cols) < 11:
                cols.append("")

            try:
                amount = float(cols[3]) if cols[3] else 0
            except:
                amount = 0

            created_at_str = re.sub(r'[^0-9]', '', str(cols[8])) if len(cols) > 8 else ""
            try:
                created_at = int(created_at_str) if created_at_str else int(time.time())
            except:
                created_at = int(time.time())

            processed_at_str = re.sub(r'[^0-9]', '', str(cols[9])) if len(cols) > 9 else "0"
            try:
                processed_at = int(processed_at_str) if processed_at_str and processed_at_str.isdigit() else 0
            except:
                processed_at = 0

            requests.append({
                'request_id': cols[0],
                'user_email': cols[1],
                'user_name': cols[2],
                'amount': amount,
                'status': cols[4] if len(cols) > 4 else 'pending',
                'bank_name': cols[5] if len(cols) > 5 else '',
                'account_number': cols[6] if len(cols) > 6 else '',
                'ifsc_code': cols[7] if len(cols) > 7 else '',
                'created_at': created_at,
                'processed_at': processed_at if processed_at > 0 else None,
                'remarks': cols[10] if len(cols) > 10 else ""
            })

    requests.sort(key=lambda x: x['created_at'], reverse=True)
    return requests[:limit]

def update_withdrawal_request_status(request_id, status, admin_email="", remarks=""):
    """Update withdrawal request status - MARKET UPDATE ONLY ON ADMIN APPROVAL"""
    ensure_files()

    if not os.path.exists(WITHDRAW_REQUEST_FILE):
        return False

    rows = []
    updated = False
    withdrawal_info = None
    previous_status = None

    with open(WITHDRAW_REQUEST_FILE, "r") as f:
        rows = f.readlines()

    with open(WITHDRAW_REQUEST_FILE, "w") as f:
        for line in rows:
            if line.startswith("request_id"):
                f.write(line)
                continue

            cols = line.strip().split("\t")
            if cols[0] == request_id:
                previous_status = cols[4]

                try:
                    withdrawal_info = {
                        "user_email": cols[1],
                        "user_name": cols[2],
                        "amount": float(cols[3]) if cols[3] else 0
                    }
                except:
                    withdrawal_info = {
                        "user_email": cols[1],
                        "user_name": cols[2],
                        "amount": 0
                    }

                cols[4] = status
                if status in ["processed", "rejected", "approved"]:
                    cols[9] = str(int(time.time()))
                if remarks:
                    while len(cols) < 11:
                        cols.append("")
                    cols[10] = remarks
                updated = True
                f.write("\t".join(cols) + "\n")
            else:
                f.write(line)

    # Handle withdrawal - NEW LOGIC
    if updated and withdrawal_info:
        user = get_user(withdrawal_info["user_email"])
        if user:
            current_internal_mrx = get_internal_mrx_balance(withdrawal_info["user_email"])

            inr_pool, mrx_pool = read_market()
            current_price = inr_pool / mrx_pool if mrx_pool > 0 else 0

            if status == "approved" and previous_status == "pending":
                # NEW CHECK: Ensure INR pool won't go below MIN_INR_POOL (1000)
                new_inr_pool = inr_pool - withdrawal_info["amount"]
                if new_inr_pool < MIN_INR_POOL:
                    # If would go below MIN_INR_POOL, refund the amount and reject
                    current_inr = float(user[6])
                    new_inr = current_inr + withdrawal_info["amount"]
                    update_user_balances(withdrawal_info["user_email"], new_inr, 0)

                    # Update status to rejected
                    update_withdrawal_request_status(request_id, "rejected", admin_email,
                                                   f"Withdrawal would bring INR pool below ₹{MIN_INR_POOL:.2f}. Current: ₹{inr_pool:.2f}, Requested: ₹{withdrawal_info['amount']:.2f}")

                    print(f"WITHDRAWAL REJECTED: INR pool protection. Would go to ₹{new_inr_pool:.2f} which is below ₹{MIN_INR_POOL:.2f}")
                    return True
                # END NEW CHECK

                # Calculate how much MRX needs to be sold internally
                if current_price > 0:
                    mrx_to_sell = withdrawal_info["amount"] / current_price

                    # Check if user has enough internal MRX
                    if current_internal_mrx < mrx_to_sell:
                        print(f"ERROR: Insufficient internal MRX for withdrawal. User: {withdrawal_info['user_email']}, MRX needed: {mrx_to_sell}, MRX available: {current_internal_mrx}")

                        # If insufficient MRX, refund the amount and mark as rejected
                        current_inr = float(user[6])
                        new_inr = current_inr + withdrawal_info["amount"]
                        update_user_balances(withdrawal_info["user_email"], new_inr, 0)

                        # Update status to rejected
                        update_withdrawal_request_status(request_id, "rejected", admin_email, "Insufficient internal MRX for withdrawal")

                        save_transaction(
                            withdrawal_info["user_email"],
                            'withdrawal_rejected_insufficient_mrx',
                            withdrawal_info["amount"],  # Refunded amount
                            0,
                            current_price
                        )

                        return True

                    # Update market pool (auto-sell MRX back to pool) - ONLY ON APPROVAL
                    new_inr_pool = inr_pool - withdrawal_info["amount"]
                    new_mrx_pool = mrx_pool + mrx_to_sell

                    # Validate price floor
                    new_price = new_inr_pool / new_mrx_pool if new_mrx_pool > 0 else current_price
                    if new_price < PRICE_FLOOR:
                        print(f"ERROR: Withdrawal would violate price floor. New price: {new_price:.4f}, Floor: {PRICE_FLOOR:.2f}")

                        # If violates price floor, refund the amount and mark as rejected
                        current_inr = float(user[6])
                        new_inr = current_inr + withdrawal_info["amount"]
                        update_user_balances(withdrawal_info["user_email"], new_inr, 0)

                        # Update status to rejected
                        update_withdrawal_request_status(request_id, "rejected", admin_email, f"Withdrawal violates price floor ₹{PRICE_FLOOR:.2f}")

                        return True

                    # Update market - ONLY HAPPENS ON ADMIN APPROVAL
                    write_market(new_inr_pool, new_mrx_pool)

                    # Update user's internal MRX balance
                    new_internal_mrx = current_internal_mrx - mrx_to_sell
                    update_internal_mrx_balance(withdrawal_info["user_email"], new_internal_mrx)

                # Save transaction
                save_transaction(
                    withdrawal_info["user_email"],
                    'withdrawal_approved',
                    0,  # No change to INR balance (already deducted on request)
                    -mrx_to_sell if current_price > 0 else 0,
                    current_price
                )

                print(f"WITHDRAWAL APPROVED: Sold {mrx_to_sell:.6f} MRX from internal balance. Market updated.")

            elif status == "rejected" and previous_status == "pending":
                # REFUND the amount back to user's wallet
                current_inr = float(user[6])
                new_inr = current_inr + withdrawal_info["amount"]
                update_user_balances(withdrawal_info["user_email"], new_inr, 0)

                save_transaction(
                    withdrawal_info["user_email"],
                    'withdrawal_rejected_refund',
                    withdrawal_info["amount"],  # Refunded amount
                    0,
                    0
                )

                print(f"WITHDRAWAL REJECTED: Refunded ₹{withdrawal_info['amount']} to {withdrawal_info['user_email']}.")

            elif status == "processed" and previous_status == "approved":
                save_transaction(
                    withdrawal_info["user_email"],
                    'withdrawal_processed',
                    0,
                    0,
                    0
                )
                print(f"WITHDRAWAL PROCESSED: Request {request_id} marked as processed.")

    if updated and admin_email:
        log_admin_action(
            admin_email,
            f"withdrawal_{status}",
            request_id,
            "withdrawal_request",
            f"Updated withdrawal from '{previous_status}' to '{status}'" +
            (f" - Amount: ₹{withdrawal_info['amount'] if withdrawal_info else 0}" if withdrawal_info else "")
        )

    return updated

def get_withdrawal_stats():
    """Get withdrawal statistics for the admin dashboard"""
    ensure_files()

    if not os.path.exists(WITHDRAW_REQUEST_FILE):
        return {
            'total': 0,
            'pending': 0,
            'approved': 0,
            'processing': 0,
            'processed': 0,
            'rejected': 0,
            'total_amount': 0
        }

    total = 0
    pending = 0
    approved = 0
    processing = 0
    processed = 0
    rejected = 0
    total_amount = 0

    with open(WITHDRAW_REQUEST_FILE, "r") as f:
        lines = f.readlines()

    for i, line in enumerate(lines):
        if i == 0:
            continue

        line = line.strip()
        if not line:
            continue

        cols = line.split("\t")

        if len(cols) >= 5:
            total += 1

            try:
                amount = float(cols[3]) if cols[3] else 0
            except:
                amount = 0
            total_amount += amount

            status = cols[4] if len(cols) > 4 else 'pending'

            if status == 'pending':
                pending += 1
            elif status == 'approved':
                approved += 1
            elif status == 'processing':
                processing += 1
            elif status == 'processed':
                processed += 1
            elif status == 'rejected':
                rejected += 1

    return {
        'total': total,
        'pending': pending,
        'approved': approved,
        'processing': processing,
        'processed': processed,
        'rejected': rejected,
        'total_amount': round(total_amount, 2)
    }

# -------------------------
# DASHBOARD STATS HELPERS
# -------------------------
def get_dashboard_stats():
    """Get comprehensive dashboard statistics"""
    users = get_all_users()
    total_users = len(users)

    total_inr = sum(user['inr_balance'] for user in users)

    inr_pool, mrx_pool = read_market()
    price = inr_pool / mrx_pool if mrx_pool > 0 else 0

    deposit_requests = get_all_deposit_requests()
    withdrawal_stats = get_withdrawal_stats()

    pending_deposits = sum(1 for d in deposit_requests if d['status'] == 'pending')
    pending_withdrawals = withdrawal_stats['pending']
    processing_withdrawals = withdrawal_stats['processing']
    total_withdrawal_amount = withdrawal_stats['total_amount']

    tax_stats = get_tax_collection_stats()

    if inr_pool > 0 and mrx_pool > 0:
        liquidity_ratio = (inr_pool * price) / (mrx_pool * price)
        if liquidity_ratio > 0.8:
            liquidity_health = "healthy"
        elif liquidity_ratio > 0.5:
            liquidity_health = "warning"
        else:
            liquidity_health = "critical"
    else:
        liquidity_health = "critical"

    recent_transactions = get_recent_transactions(limit=10)
    recent_logs = get_recent_admin_logs(limit=10)

    total_internal_mrx = get_total_internal_mrx()

    return {
        'total_users': total_users,
        'total_inr': round(total_inr, 2),
        'total_mrx': 0.0,
        'total_internal_mrx': round(total_internal_mrx, 6),
        'current_price': round(price, 4),
        'inr_pool': round(inr_pool, 2),
        'mrx_pool': round(mrx_pool, 6),
        'pending_deposits': pending_deposits,
        'pending_withdrawals': pending_withdrawals,
        'processing_withdrawals': processing_withdrawals,
        'total_withdrawal_amount': total_withdrawal_amount,
        'liquidity_health': liquidity_health,
        'active_users': len([u for u in users if u['inr_balance'] > 1000 or get_internal_mrx_balance(u['email']) > 0]),
        'recent_transactions': recent_transactions,
        'recent_logs': recent_logs,
        'withdrawal_stats': withdrawal_stats,
        'tax_stats': tax_stats,
        'internal_mrx_reconciliation': round(mrx_pool + total_internal_mrx, 6),
        'min_inr_pool': MIN_INR_POOL,  # NEW: Include min INR pool in stats
        'inr_pool_above_min': inr_pool >= MIN_INR_POOL  # NEW: Check if above minimum
    }

# ========================
# CORRECTED ACCELERATE-ORDER ENDPOINT
# User's wallet shows: remaining INR + value of MRX investment
# ========================
@app.route("/api/accelerate-order", methods=["POST"])
def accelerate_order():
    if "user" not in session:
        return jsonify({"success": False, "error": "Not logged in"}), 401

    data = request.get_json()
    if not data:
        return jsonify({"success": False, "error": "Invalid request"}), 400

    amount = float(data.get("amount", 0))
    sentiment = data.get("sentiment", "bullish")

    if amount > MAX_SINGLE_ORDER:
        return jsonify({
            "success": False,
            "error": f"Single order cannot exceed ₹{MAX_SINGLE_ORDER:.2f}. Your order: ₹{amount:.2f}"
        }), 400

    if amount <= 0:
        return jsonify({"success": False, "error": "Invalid amount"}), 400

    # NEW: Check daily trading limit
    limit_check, limit_msg = check_daily_trading_limit(session["user"], amount)
    if not limit_check:
        return jsonify({
            "success": False,
            "error": limit_msg
        }), 400

    # DISABLE SELL ORDERS FOR USERS
    if sentiment == "bearish":
        return jsonify({
            "success": False,
            "error": "Sell is disabled. Withdraw to exit your position."
        }), 400

    if sentiment != "bullish":
        return jsonify({"success": False, "error": "Invalid operation"}), 400

    user = get_user(session["user"])
    if not user:
        return jsonify({"success": False, "error": "User not found"}), 404

    inr_balance = float(user[6])
    user_name = user[1]

    inr_pool, mrx_pool = read_market()
    if mrx_pool <= 0:
        return jsonify({"success": False, "error": "Market unavailable"}), 500

    price_before = inr_pool / mrx_pool

    # ==== CORRECTED CALCULATION ====
    tax_amount = amount * TAX_RATE
    amount_after_tax = amount - tax_amount

    if inr_balance < amount:
        return jsonify({
            "success": False,
            "error": f"Insufficient INR balance. Required: ₹{amount:.2f}. Available: ₹{inr_balance:.2f}"
        }), 400

    # MRX allocation
    mrx_received = amount_after_tax / price_before

    # New pool state
    new_inr_pool = inr_pool + amount_after_tax
    new_mrx_pool = mrx_pool - mrx_received

    # New price
    new_price = new_inr_pool / new_mrx_pool if new_mrx_pool > 0 else price_before

    # Validate price floor
    if new_price < PRICE_FLOOR:
        return jsonify({
            "success": False,
            "error": f"Cannot execute trade. Price would drop below ₹{PRICE_FLOOR:.2f} floor.",
            "current_price": round(price_before, 4),
            "projected_price": round(new_price, 4),
            "price_floor": PRICE_FLOOR
        }), 400

    # Check liquidity protection
    if mrx_received > mrx_pool * 0.95:
        return jsonify({"success": False, "error": "Market liquidity too low"}), 400

    # ===== CORRECT WALLET BALANCE CALCULATION =====
    # MRX value at NEW price (current market value)
    mrx_value_at_new_price = mrx_received * new_price

    # User's new INR balance should be:
    # Old balance - amount invested + current value of MRX
    new_inr = inr_balance - amount + mrx_value_at_new_price

    # Profit calculation (for reporting only)
    actual_profit = mrx_value_at_new_price - amount

    # Execute the trade
    write_market(new_inr_pool, new_mrx_pool)

    # Update internal MRX balance
    current_internal_mrx = get_internal_mrx_balance(session["user"])
    new_internal_mrx = current_internal_mrx + mrx_received
    update_internal_mrx_balance(session["user"], new_internal_mrx)

    # Update user's INR balance (show MRX value in wallet)
    update_user_balances(session["user"], new_inr, 0)

    # NEW: Update daily trading total
    update_user_daily_trades(session["user"], amount)

    # Save transaction
    txn_id = save_transaction(
        session['user'],
        'accelerate_bullish',
        -amount + mrx_value_at_new_price,  # Net change in wallet
        mrx_received,
        price_before
    )

    # Log tax collection for buy order
    tax_log_id = log_tax_collection(
        user_email=session['user'],
        user_name=user_name,
        order_type="buy",
        order_amount=amount,
        tax_amount=tax_amount,
        order_worth=mrx_value_at_new_price,
        remarks=f"Buy order tax: {TAX_RATE*100}%, MRX allocated: {mrx_received:.6f}, MRX value: ₹{mrx_value_at_new_price:.2f}"
    )

    # Save order record
    order_id = save_order_record(
        user_email=session['user'],
        user_name=user_name,
        order_type="buy",
        order_amount_inr=amount,
        order_amount_mrx=mrx_received,
        price_at_order=price_before,
        tax_amount=tax_amount,
        remarks=f"BUY: Invested ₹{amount:.2f}, got {mrx_received:.6f} MRX worth ₹{mrx_value_at_new_price:.2f} at new price"
    )

    percentage_return = (actual_profit / amount) * 100 if amount > 0 else 0

    # Get updated daily total
    daily_total = get_user_daily_trades(session["user"])
    daily_remaining = DAILY_TRADING_LIMIT - daily_total

    response_data = {
        "success": True,
        "transaction_id": txn_id,
        "tax_log_id": tax_log_id,
        "order_id": order_id,
        "new_inr_balance": round(new_inr, 2),
        "new_mrx_balance": 0,
        "price_before": round(price_before, 4),
        "price_after": round(new_price, 4),
        "sentiment": sentiment,
        "inr_invested": round(amount, 2),
        "tax_amount": round(tax_amount, 2),
        "amount_to_pool": round(amount_after_tax, 2),
        "tax_rate": f"{TAX_RATE*100}%",
        "mrx_allocated_internally": round(mrx_received, 6),
        "price_floor": PRICE_FLOOR,
        "price_floor_violation": False,
        "single_order_limit": MAX_SINGLE_ORDER,
        "within_limit": amount <= MAX_SINGLE_ORDER,

        # NEW: Daily trading limit info
        "daily_trading_limit": DAILY_TRADING_LIMIT,
        "daily_total_used": round(daily_total, 2),
        "daily_remaining": round(daily_remaining, 2),
        "daily_limit_reached": daily_total >= DAILY_TRADING_LIMIT,

        # ===== CORRECT VALUES =====
        "mrx_current_value": round(mrx_value_at_new_price, 2),
        "profit": round(actual_profit, 2),
        "profit_calculation": f"({mrx_received:.6f} MRX × ₹{new_price:.4f}) - ₹{amount:.2f}",
        "wallet_calculation": f"₹{inr_balance:.2f} - ₹{amount:.2f} + ₹{mrx_value_at_new_price:.2f} = ₹{new_inr:.2f}",
        "new_price": round(new_price, 4),
        "percentage_return": round(percentage_return, 2),

        # ===== USER MESSAGES =====
        "user_message": f"Invested ₹{amount:.2f} (₹{tax_amount:.2f} tax). Your MRX is now worth ₹{mrx_value_at_new_price:.2f}. Wallet: ₹{new_inr:.2f}",
        "summary": f"Investment: ₹{amount:.2f}, Tax: ₹{tax_amount:.2f}, MRX Value: ₹{mrx_value_at_new_price:.2f}, Profit: ₹{actual_profit:.2f} ({percentage_return:.1f}%)",
    }

    return jsonify(response_data)

# -------------------------
# USER BALANCE API
# -------------------------
@app.route("/api/user-balance")
def api_user_balance():
    if "user" not in session:
        return jsonify({"error": "Not logged in"}), 401

    user = get_user(session["user"])
    if not user:
        return jsonify({"error": "User not found"}), 404

    # Calculate current total value including internal MRX
    inr_balance = float(user[6])
    internal_mrx = get_internal_mrx_balance(session["user"])

    inr_pool, mrx_pool = read_market()
    current_price = inr_pool / mrx_pool if mrx_pool > 0 else 0

    # Total value shown in wallet = INR balance + (internal MRX × current price)
    total_wallet_value = inr_balance + (internal_mrx * current_price)

    # NEW: Get daily trading stats
    daily_total = get_user_daily_trades(session["user"])
    daily_remaining = DAILY_TRADING_LIMIT - daily_total

    return jsonify({
        "success": True,
        "inr_balance": float(user[6]),
        "mrx_balance": 0,
        "internal_mrx_balance": internal_mrx,
        "current_price": round(current_price, 4),
        "total_wallet_value": round(total_wallet_value, 2),
        "daily_trading_limit": DAILY_TRADING_LIMIT,
        "daily_total_used": round(daily_total, 2),
        "daily_remaining": round(daily_remaining, 2),
        "daily_limit_reached": daily_total >= DAILY_TRADING_LIMIT
    })

# -------------------------
# PRICE API
# -------------------------
@app.route("/api/price")
def api_price():
    inr_pool, mrx_pool = read_market()

    if mrx_pool <= 0:
        return jsonify({
            "success": False,
            "price": 0,
            "inr_pool": inr_pool,
            "mrx_pool": mrx_pool,
            "error": "Invalid market state"
        }), 500

    price = round(inr_pool / mrx_pool, 4)
    return jsonify({
        "success": True,
        "price": price,
        "inr_pool": round(inr_pool, 2),
        "mrx_pool": round(mrx_pool, 6),
        "min_inr_pool": MIN_INR_POOL,
        "inr_pool_above_min": inr_pool >= MIN_INR_POOL
    })

# -------------------------
# USER TRANSACTIONS API
# -------------------------
@app.route("/api/user/transactions")
def api_user_transactions():
    if "user" not in session:
        return jsonify({"error": "Not logged in"}), 401

    limit = request.args.get("limit", 50, type=int)
    transactions = get_user_transactions(session["user"], limit)

    return jsonify({
        "success": True,
        "transactions": transactions,
        "count": len(transactions)
    })

# -------------------------
# DEPOSIT REQUEST API
# -------------------------
@app.route("/api/deposit-request", methods=["POST"])
def api_deposit_request():
    if "user" not in session:
        return jsonify({"success": False, "error": "Not logged in"}), 401

    data = request.get_json()
    if not data:
        return jsonify({"success": False, "error": "Invalid request"}), 400

    amount = float(data.get("amount", 0))
    txn_id = data.get("transaction_id", "").strip()
    phone = data.get("phone", "").strip()
    method = data.get("payment_method", "upi")

    if amount < 100 or amount > 100000:
        return jsonify({"success": False, "error": "Amount must be between ₹500 and ₹100,000"}), 400

    if len(txn_id) < 5:
        return jsonify({"success": False, "error": "Invalid transaction ID (min 5 characters)"}), 400

    user_email = session["user"]
    user = get_user(user_email)
    if not user:
        return jsonify({"success": False, "error": "User not found"}), 404

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
        "status": "pending",
        "amount": amount
    })

@app.route("/api/deposit-history")
def api_deposit_history():
    if "user" not in session:
        return jsonify({"success": False, "error": "Not logged in"}), 401

    limit = request.args.get("limit", 10, type=int)
    deposits = get_user_deposit_requests(session["user"], limit)

    return jsonify({
        "success": True,
        "deposits": deposits,
        "count": len(deposits)
    })

# -------------------------
# WITHDRAWAL API - UPDATED WITH INR POOL PROTECTION
# -------------------------
@app.route("/api/withdraw", methods=["POST"])
def api_withdraw():
    if "user" not in session:
        return jsonify({"success": False, "error": "Not logged in"}), 401

    data = request.get_json()
    amount = float(data.get("amount", 0))
    bank_name = data.get("bank_name", "")
    account_number = data.get("account_number", "")
    ifsc_code = data.get("ifsc_code", "")

    if amount <= 0:
        return jsonify({"success": False, "error": "Invalid amount"}), 400

    if not bank_name or not account_number or not ifsc_code:
        return jsonify({"success": False, "error": "All bank details are required"}), 400

    user = get_user(session["user"])
    if not user:
        return jsonify({"success": False, "error": "User not found"}), 404

    current_inr = float(user[6])

    if current_inr < amount:
        return jsonify({"success": False, "error": "Insufficient balance"}), 400

    # NEW: Check if withdrawal would bring INR pool below MIN_INR_POOL (1000)
    inr_pool, mrx_pool = read_market()
    new_inr_pool = inr_pool - amount

    if new_inr_pool < MIN_INR_POOL:
        return jsonify({
            "success": False,
            "error": f"Withdrawal not allowed. Would bring INR pool below ₹{MIN_INR_POOL:.2f}. Current pool: ₹{inr_pool:.2f}, Requested: ₹{amount:.2f}"
        }), 400

    request_id = save_withdraw_request(
        session['user'],
        user[1],
        amount,
        bank_name,
        account_number,
        ifsc_code
    )

    if not request_id:
        return jsonify({"success": False, "error": "Failed to create withdrawal request"}), 400

    # NEW: Amount already deducted in save_withdraw_request
    return jsonify({
        "success": True,
        "request_id": request_id,
        "message": f"Withdrawal request submitted successfully. ₹{amount:.2f} deducted from your wallet.",
        "amount_requested": amount,
        "tax_amount": 0,
        "amount_after_tax": amount,
        "current_balance": round(current_inr - amount, 2),
        "note": "Amount will be refunded if request is rejected by admin."
    })

@app.route("/api/withdrawal-requests")
def api_withdrawal_requests():
    if "user" not in session:
        return jsonify({"error": "Not logged in"}), 401

    withdrawal_requests = get_user_withdrawal_requests(session["user"])

    return jsonify({
        "success": True,
        "withdrawal_requests": withdrawal_requests,
        "count": len(withdrawal_requests)
    })

# -------------------------
# DAILY LIMIT API (NEW)
# -------------------------
@app.route("/api/daily-limit")
def api_daily_limit():
    if "user" not in session:
        return jsonify({"error": "Not logged in"}), 401

    daily_total = get_user_daily_trades(session["user"])
    daily_remaining = DAILY_TRADING_LIMIT - daily_total

    return jsonify({
        "success": True,
        "daily_limit": DAILY_TRADING_LIMIT,
        "daily_total": round(daily_total, 2),
        "daily_remaining": round(daily_remaining, 2),
        "limit_reached": daily_total >= DAILY_TRADING_LIMIT,
        "date": date.today().isoformat()
    })

# -------------------------
# ADMIN API ENDPOINTS
# -------------------------
@app.route("/api/admin/dashboard-stats")
def api_admin_dashboard_stats():
    if "user" not in session:
        return jsonify({"success": False, "error": "Not logged in"}), 401

    if not is_admin(session["user"]):
        return jsonify({"success": False, "error": "Access denied"}), 403

    stats = get_dashboard_stats()

    return jsonify({
        "success": True,
        "stats": stats,
        "timestamp": int(time.time())
    })

@app.route("/api/admin/update-deposit-status", methods=["POST"])
def api_admin_update_deposit_status():
    if "user" not in session:
        return jsonify({"success": False, "error": "Not logged in"}), 401

    if not is_admin(session["user"]):
        return jsonify({"success": False, "error": "Access denied"}), 403

    data = request.get_json()
    request_id = data.get("request_id", "").strip()
    status = data.get("status", "").strip()

    if not request_id:
        return jsonify({"success": False, "error": "Request ID required"}), 400

    if status not in ["approved", "rejected", "pending"]:
        return jsonify({"success": False, "error": "Invalid status"}), 400

    updated = update_deposit_request_status(request_id, status, session["user"])

    if not updated:
        return jsonify({"success": False, "error": "Deposit request not found"}), 404

    return jsonify({
        "success": True,
        "message": f"Deposit request {request_id} updated to '{status}'",
        "request_id": request_id,
        "status": status
    })

@app.route("/api/admin/update-withdrawal-status", methods=["POST"])
def api_admin_update_withdrawal_status():
    if "user" not in session:
        return jsonify({"success": False, "error": "Not logged in"}), 401

    if not is_admin(session["user"]):
        return jsonify({"success": False, "error": "Access denied"}), 403

    data = request.get_json()
    request_id = data.get("request_id", "").strip()
    status = data.get("status", "").strip()
    remarks = data.get("remarks", "").strip()

    if not request_id:
        return jsonify({"success": False, "error": "Request ID required"}), 400

    if status not in ["approved", "rejected", "processing", "pending", "processed"]:
        return jsonify({"success": False, "error": "Invalid status"}), 400

    updated = update_withdrawal_request_status(request_id, status, session["user"], remarks)

    if not updated:
        return jsonify({"success": False, "error": "Withdrawal request not found"}), 404

    return jsonify({
        "success": True,
        "message": f"Withdrawal request {request_id} updated to '{status}'",
        "request_id": request_id,
        "status": status
    })

@app.route("/api/admin/deposit-requests")
def api_admin_deposit_requests():
    if "user" not in session:
        return jsonify({"success": False, "error": "Not logged in"}), 401

    if not is_admin(session["user"]):
        return jsonify({"success": False, "error": "Access denied"}), 403

    limit = request.args.get("limit", 50, type=int)
    status_filter = request.args.get("status", "")

    deposit_requests = get_all_deposit_requests(limit)

    if status_filter:
        deposit_requests = [req for req in deposit_requests if req["status"] == status_filter]

    return jsonify({
        "success": True,
        "deposit_requests": deposit_requests,
        "count": len(deposit_requests),
        "filter_status": status_filter
    })

@app.route("/api/admin/withdrawal-requests")
def api_admin_withdrawal_requests():
    if "user" not in session:
        return jsonify({"success": False, "error": "Not logged in"}), 401

    if not is_admin(session["user"]):
        return jsonify({"success": False, "error": "Access denied"}), 403

    limit = request.args.get("limit", 1000, type=int)
    status_filter = request.args.get("status", "")
    search = request.args.get("search", "").strip().lower()

    all_requests = get_all_withdrawal_requests(limit=limit)

    if search:
        filtered_requests = []
        for req in all_requests:
            search_match = (
                (req.get('user_email', '').lower().find(search) >= 0) or
                (req.get('user_name', '').lower().find(search) >= 0) or
                (req.get('request_id', '').lower().find(search) >= 0) or
                (req.get('bank_name', '').lower().find(search) >= 0) or
                (str(req.get('account_number', '')).find(search) >= 0) or
                (req.get('ifsc_code', '').lower().find(search) >= 0)
            )
            if search_match:
                filtered_requests.append(req)
        all_requests = filtered_requests

    if status_filter and status_filter != 'all':
        all_requests = [req for req in all_requests if req.get("status", "") == status_filter]

    stats = get_withdrawal_stats()

    formatted_requests = []
    for req in all_requests:
        account_number = str(req.get('account_number', ''))
        masked_account = f"****{account_number[-4:]}" if account_number and len(account_number) >= 4 else "****"

        formatted_req = {
            'request_id': req.get('request_id', ''),
            'user_email': req.get('user_email', ''),
            'user_name': req.get('user_name', ''),
            'amount': float(req.get('amount', 0)),
            'status': req.get('status', 'pending'),
            'bank_name': req.get('bank_name', ''),
            'account_number': req.get('account_number', ''),
            'masked_account': masked_account,
            'ifsc_code': req.get('ifsc_code', ''),
            'created_at': int(req.get('created_at', time.time())),
            'processed_at': int(req.get('processed_at', 0)) if req.get('processed_at') else None,
            'remarks': req.get('remarks', '')
        }
        formatted_requests.append(formatted_req)

    return jsonify({
        "success": True,
        "withdrawal_requests": formatted_requests,
        "stats": stats,
        "count": len(formatted_requests),
        "filter_status": status_filter,
        "search_query": search
    })

@app.route("/api/admin/users")
def api_admin_users():
    if "user" not in session:
        return jsonify({"success": False, "error": "Not logged in"}), 401

    if not is_admin(session["user"]):
        return jsonify({"success": False, "error": "Access denied"}), 403

    users = get_all_users()

    inr_pool, mrx_pool = read_market()
    price = inr_pool / mrx_pool if mrx_pool > 0 else 0

    for user in users:
        user['total_value'] = round(user['inr_balance'] + (get_internal_mrx_balance(user['email']) * price), 2)
        user['is_admin'] = is_admin(user['email'])
        user['internal_mrx_balance'] = get_internal_mrx_balance(user['email'])

        # NEW: Add daily trading info
        user['daily_trades_today'] = round(get_user_daily_trades(user['email']), 2)
        user['daily_remaining'] = round(DAILY_TRADING_LIMIT - user['daily_trades_today'], 2)
        user['daily_limit_reached'] = user['daily_trades_today'] >= DAILY_TRADING_LIMIT

    return jsonify({
        "success": True,
        "users": users,
        "count": len(users),
        "market_price": round(price, 4),
        "daily_trading_limit": DAILY_TRADING_LIMIT
    })

@app.route("/api/admin/user/<user_email>")
def api_admin_user_detail(user_email):
    if "user" not in session:
        return jsonify({"success": False, "error": "Not logged in"}), 401

    if not is_admin(session["user"]):
        return jsonify({"success": False, "error": "Access denied"}), 403

    user_data = get_user(user_email)
    if not user_data:
        return jsonify({"success": False, "error": "User not found"}), 404

    transactions = get_user_transactions(user_email, limit=20)
    deposit_requests = get_user_deposit_requests(user_email, limit=10)
    withdrawal_requests = get_user_withdrawal_requests(user_email)
    internal_mrx_balance = get_internal_mrx_balance(user_email)

    # NEW: Get daily trading stats
    daily_trades = get_user_daily_trades(user_email)
    daily_remaining = DAILY_TRADING_LIMIT - daily_trades

    return jsonify({
        "success": True,
        "user": {
            'user_id': user_data[0],
            'full_name': user_data[1],
            'email': user_data[2],
            'mobile': user_data[3],
            'inr_balance': float(user_data[6]),
            'mrx_balance': 0,
            'internal_mrx_balance': internal_mrx_balance,
            'created_at': int(user_data[8]),
            'referral': user_data[5],
            'daily_trades_today': round(daily_trades, 2),
            'daily_remaining': round(daily_remaining, 2),
            'daily_limit_reached': daily_trades >= DAILY_TRADING_LIMIT
        },
        "transactions": transactions,
        "deposit_requests": deposit_requests,
        "withdrawal_requests": withdrawal_requests,
        "transaction_count": len(transactions)
    })

@app.route("/api/admin/logs")
def api_admin_logs():
    if "user" not in session:
        return jsonify({"success": False, "error": "Not logged in"}), 401

    if not is_admin(session["user"]):
        return jsonify({"success": False, "error": "Access denied"}), 403

    limit = request.args.get("limit", 50, type=int)
    logs = get_recent_admin_logs(limit)

    return jsonify({
        "success": True,
        "logs": logs,
        "count": len(logs)
    })

@app.route("/api/admin/tax-stats")
def api_admin_tax_stats():
    if "user" not in session:
        return jsonify({"success": False, "error": "Not logged in"}), 401

    if not is_admin(session["user"]):
        return jsonify({"success": False, "error": "Access denied"}), 403

    tax_stats = get_tax_collection_stats()

    return jsonify({
        "success": True,
        "tax_stats": tax_stats,
        "timestamp": int(time.time())
    })

@app.route("/api/admin/tax-records")
def api_admin_tax_records():
    if "user" not in session:
        return jsonify({"success": False, "error": "Not logged in"}), 401

    if not is_admin(session["user"]):
        return jsonify({"success": False, "error": "Access denied"}), 403

    limit = request.args.get("limit", 100, type=int)
    order_type = request.args.get("order_type", "")
    search = request.args.get("search", "").strip().lower()

    records = get_tax_collection_records(limit)

    if order_type and order_type != "all":
        records = [r for r in records if r['order_type'] == order_type]

    if search:
        filtered_records = []
        for record in records:
            search_match = (
                (record.get('user_email', '').lower().find(search) >= 0) or
                (record.get('user_name', '').lower().find(search) >= 0) or
                (record.get('tax_id', '').lower().find(search) >= 0)
            )
            if search_match:
                filtered_records.append(record)
        records = filtered_records

    total_tax = sum(r['tax_amount'] for r in records)
    total_orders = len(records)

    return jsonify({
        "success": True,
        "tax_records": records,
        "count": total_orders,
        "total_tax": round(total_tax, 2),
        "filter_order_type": order_type,
        "search_query": search
    })

@app.route("/api/admin/system-health")
def api_admin_system_health():
    if "user" not in session:
        return jsonify({"success": False, "error": "Not logged in"}), 401

    if not is_admin(session["user"]):
        return jsonify({"success": False, "error": "Access denied"}), 403

    try:
        file_stats = {}
        for file_path, file_name in [
            (USERS_FILE, "users.tsv"),
            (TXN_FILE, "transactions.tsv"),
            (MARKET_FILE, "market.tsv"),
            (WITHDRAW_REQUEST_FILE, "withdraw_request.tsv"),
            (DEPOSIT_REQUEST_FILE, "deposit_request.tsv"),
            (ADMIN_LOG_FILE, "admin_log.tsv"),
            (TAX_COLLECTION_FILE, "tax_collection.tsv"),
            (ORDERS_FILE, "orders.tsv"),
            (INTERNAL_MRX_FILE, "internal_mrx.tsv"),
            (DAILY_TRADES_FILE, "daily_trades.tsv")  # NEW
        ]:
            if os.path.exists(file_path):
                size = os.path.getsize(file_path)
                with open(file_path, 'r') as f:
                    lines = len(f.readlines())
                file_stats[file_name] = {
                    'size_kb': round(size / 1024, 2),
                    'line_count': lines - 1 if lines > 1 else 0,
                    'exists': True
                }
            else:
                file_stats[file_name] = {'exists': False}

        inr_pool, mrx_pool = read_market()
        price = inr_pool / mrx_pool if mrx_pool > 0 else 0

        users = get_all_users()

        tax_stats = get_tax_collection_stats()

        total_internal_mrx = get_total_internal_mrx()
        initial_mrx = 1000.0

        # NEW: Daily limit stats
        today = date.today().isoformat()
        daily_trades = []
        if os.path.exists(DAILY_TRADES_FILE):
            with open(DAILY_TRADES_FILE, "r") as f:
                lines = f.readlines()
            for i, line in enumerate(lines):
                if i == 0:
                    continue
                cols = line.strip().split("\t")
                if len(cols) >= 3 and cols[0] == today:
                    daily_trades.append({
                        'user_email': cols[1],
                        'amount': float(cols[2]) if cols[2] else 0
                    })

        return jsonify({
            "success": True,
            "timestamp": int(time.time()),
            "files": file_stats,
            "market": {
                "inr_pool": round(inr_pool, 2),
                "mrx_pool": round(mrx_pool, 6),
                "price": round(price, 4),
                "price_floor": PRICE_FLOOR,
                "above_floor": price >= PRICE_FLOOR,
                "liquidity": round(inr_pool + (mrx_pool * price), 2),
                "min_inr_pool": MIN_INR_POOL,
                "inr_pool_above_min": inr_pool >= MIN_INR_POOL
            },
            "internal_mrx": {
                "total_internal_mrx": round(total_internal_mrx, 6),
                "system_mrx_total": round(mrx_pool + total_internal_mrx, 6),
                "initial_mrx": initial_mrx,
                "reconciled": abs((mrx_pool + total_internal_mrx) - initial_mrx) < 0.001,
                "discrepancy": round(abs((mrx_pool + total_internal_mrx) - initial_mrx), 6)
            },
            "users": {
                "total": len(users),
                "active": len([u for u in users if u['inr_balance'] > 0 or get_internal_mrx_balance(u['email']) > 0])
            },
            "daily_trading": {
                "limit_per_user": DAILY_TRADING_LIMIT,
                "active_traders_today": len(daily_trades),
                "total_volume_today": sum(d['amount'] for d in daily_trades),
                "users_at_limit": len([d for d in daily_trades if d['amount'] >= DAILY_TRADING_LIMIT])
            },
            "tax": tax_stats,
            "system": {
                "uptime": int(time.time() - os.path.getctime(DATA_DIR)) if os.path.exists(DATA_DIR) else 0,
                "data_dir": DATA_DIR
            }
        })
    except Exception as e:
        return jsonify({
            "success": False,
            "error": str(e),
            "timestamp": int(time.time())
        }), 500

@app.route("/api/admin/adjust-pool", methods=["POST"])
def api_admin_adjust_pool():
    if "user" not in session:
        return jsonify({"success": False, "error": "Not logged in"}), 401

    if not is_admin(session["user"]):
        return jsonify({"success": False, "error": "Access denied"}), 403

    data = request.get_json()
    new_inr = float(data.get("inr_pool", 0))
    new_mrx = float(data.get("mrx_pool", 0))

    if new_mrx > 0 and (new_inr / new_mrx) < PRICE_FLOOR:
        return jsonify({
            "success": False,
            "error": f"Cannot set pool. Price would be ₹{new_inr/new_mrx:.4f} which is below ₹{PRICE_FLOOR:.2f} floor",
            "minimum_inr_for_mrx": new_mrx * PRICE_FLOOR,
            "maximum_mrx_for_inr": new_inr / PRICE_FLOOR if PRICE_FLOOR > 0 else 0
        }), 400

    try:
        write_market(new_inr, new_mrx)

        log_admin_action(
            session["user"],
            "adjust_pool",
            "market",
            "pool",
            f"Adjusted pool to ₹{new_inr:.2f}/{new_mrx:.6f} MRX (Price: ₹{new_inr/new_mrx:.4f})"
        )

        return jsonify({
            "success": True,
            "message": "Pool adjusted successfully",
            "new_inr_pool": new_inr,
            "new_mrx_pool": new_mrx,
            "new_price": round(new_inr / new_mrx, 4) if new_mrx > 0 else 0,
            "price_floor": PRICE_FLOOR,
            "above_floor": (new_inr / new_mrx) >= PRICE_FLOOR if new_mrx > 0 else True,
            "min_inr_pool": MIN_INR_POOL,
            "inr_pool_above_min": new_inr >= MIN_INR_POOL
        })
    except ValueError as e:
        return jsonify({
            "success": False,
            "error": str(e)
        }), 400

@app.route("/api/admin/get-price-floor")
def api_admin_get_price_floor():
    if "user" not in session or not is_admin(session["user"]):
        return jsonify({"success": False, "error": "Access denied"}), 403

    inr_pool, mrx_pool = read_market()
    current_price = inr_pool / mrx_pool if mrx_pool > 0 else 0

    return jsonify({
        "success": True,
        "price_floor": PRICE_FLOOR,
        "current_price": round(current_price, 4),
        "above_floor": current_price >= PRICE_FLOOR,
        "margin": round(current_price - PRICE_FLOOR, 4) if current_price >= PRICE_FLOOR else 0,
        "min_inr_pool": MIN_INR_POOL,
        "inr_pool_above_min": inr_pool >= MIN_INR_POOL
    })

# NEW: Admin reset daily limit endpoint
@app.route("/api/admin/reset-daily-limit", methods=["POST"])
def api_admin_reset_daily_limit():
    if "user" not in session:
        return jsonify({"success": False, "error": "Not logged in"}), 401

    if not is_admin(session["user"]):
        return jsonify({"success": False, "error": "Access denied"}), 403

    data = request.get_json()
    user_email = data.get("user_email", "").strip()

    if not user_email:
        return jsonify({"success": False, "error": "User email required"}), 400

    # Reset daily trades for specific user
    ensure_files()
    today = date.today().isoformat()

    if not os.path.exists(DAILY_TRADES_FILE):
        return jsonify({"success": True, "message": "Daily trades file doesn't exist"})

    rows = []
    with open(DAILY_TRADES_FILE, "r") as f:
        rows = f.readlines()

    with open(DAILY_TRADES_FILE, "w") as f:
        f.write(rows[0])  # Header
        for line in rows[1:]:
            cols = line.strip().split("\t")
            if len(cols) >= 3 and cols[0] == today and cols[1] == user_email:
                # Skip this line (reset)
                log_admin_action(
                    session["user"],
                    "reset_daily_limit",
                    user_email,
                    "user",
                    f"Reset daily trading limit for {user_email}. Was: ₹{cols[2]}"
                )
            else:
                f.write(line)

    return jsonify({
        "success": True,
        "message": f"Daily trading limit reset for {user_email}",
        "user_email": user_email,
        "date": today
    })

@app.route("/api/market/stats")
def api_market_stats():
    inr_pool, mrx_pool = read_market()
    price = inr_pool / mrx_pool if mrx_pool > 0 else 0

    users = get_all_users()
    total_users = len(users)
    total_inr = sum(user['inr_balance'] for user in users)
    total_mrx = 0
    total_value = total_inr

    return jsonify({
        "success": True,
        "market": {
            "price": round(price, 4),
            "inr_pool": round(inr_pool, 2),
            "mrx_pool": round(mrx_pool, 6),
            "price_floor": PRICE_FLOOR,
            "above_floor": price >= PRICE_FLOOR,
            "liquidity": round(inr_pool * 2, 2),
            "min_inr_pool": MIN_INR_POOL,
            "inr_pool_above_min": inr_pool >= MIN_INR_POOL
        },
        "network": {
            "total_users": total_users,
            "total_value": round(total_value, 2),
            "active_users": len([u for u in users if u['inr_balance'] > 0])
        }
    })

@app.route("/api/health")
def api_health():
    try:
        ensure_files()
        inr_pool, mrx_pool = read_market()
        price = inr_pool / mrx_pool if mrx_pool > 0 else 0

        return jsonify({
            "status": "healthy",
            "market_operational": mrx_pool > 0,
            "inr_pool": inr_pool,
            "mrx_pool": mrx_pool,
            "current_price": round(price, 4),
            "price_floor": PRICE_FLOOR,
            "above_floor": price >= PRICE_FLOOR,
            "min_inr_pool": MIN_INR_POOL,
            "inr_pool_above_min": inr_pool >= MIN_INR_POOL,
            "timestamp": int(time.time())
        })
    except Exception as e:
        return jsonify({
            "status": "unhealthy",
            "error": str(e),
            "timestamp": int(time.time())
        }), 500

# -------------------------
# BASIC ROUTES
# -------------------------
@app.route("/")
def home():
    return redirect(url_for("login"))

@app.route("/about")
def about():
    return render_template("about.html", section="about")

@app.route("/legal")
def legal():
    return render_template("about.html", section="legal")

@app.route("/privacy")
def privacy():
    return render_template("about.html", section="privacy")

@app.route("/signup", methods=["GET", "POST"])
def signup():
    ensure_files()

    if request.method == "POST":
        email = request.form.get("email")
        password = request.form.get("password")
        full_name = request.form.get("fullName")
        mobile = request.form.get("mobile")

        if not email or not password or not full_name:
            return "Missing required fields", 400

        if get_user(email):
            return "Email already registered", 400

        user_id = str(uuid.uuid4())[:8]
        created_at = int(time.time())
        referral = request.form.get("referralCode", "")

        with open(USERS_FILE, "a") as f:
            f.write(
                f"{user_id}\t{full_name}\t{email}\t"
                f"{mobile}\t{password}\t{referral}\t"
                f"0\t0\t{created_at}\n"
            )

        session["user"] = email
        response = make_response(redirect(url_for("trade")))
        response.set_cookie('user_email', email, max_age=30*24*60*60)
        return response

    return render_template("signup.html")

@app.route("/login", methods=["GET", "POST"])
def login():
    ensure_files()

    if request.method == "POST":
        email = request.form.get("email")
        password = request.form.get("password")

        user = get_user(email)
        if not user or user[4] != password:
            return render_template("login.html", error="Invalid credentials")

        session["user"] = email

        if is_admin(email):
            return redirect(url_for("admin_dashboard"))
        else:
            return redirect(url_for("trade"))

    return render_template("login.html")

@app.route("/admin-login")
def admin_login():
    if not get_user("admin@unitedworld.com"):
        user_id = "ADMIN001"
        created_at = int(time.time())

        with open(USERS_FILE, "a") as f:
            f.write(
                f"{user_id}\tAdmin Wilson\tadmin@unitedworld.com\t"
                f"9876543210\tadmin123\tADMIN001\t"
                f"1000000\t0\t{created_at}\n"
            )

    session["user"] = "admin@unitedworld.com"
    return redirect(url_for("admin_dashboard"))

@app.route("/logout")
def logout():
    session.clear()
    response = make_response(redirect(url_for("login")))
    response.set_cookie('user_email', '', expires=0)
    return response

@app.route("/trade")
def trade():
    if "user" not in session:
        return redirect(url_for("login"))

    user = get_user(session["user"])
    if not user:
        return redirect(url_for("logout"))

    return render_template("trade.html")

@app.route("/wallet")
def wallet():
    if "user" not in session:
        return redirect(url_for("login"))

    user = get_user(session["user"])
    if not user:
        return redirect(url_for("logout"))

    transactions = get_user_transactions(session["user"], limit=20)
    withdrawal_requests = get_user_withdrawal_requests(session["user"])
    deposit_requests = get_user_deposit_requests(session["user"])

    return render_template(
        "wallet.html",
        user_name=user[1],
        inr_balance=user[6],
        mrx_balance=0,
        transactions=transactions,
        withdrawal_requests=withdrawal_requests,
        deposit_requests=deposit_requests
    )

@app.route("/account")
def account():
    if "user" not in session:
        return redirect(url_for("login"))

    user = get_user(session["user"])
    if not user:
        return redirect(url_for("logout"))

    transactions = get_user_transactions(session["user"], limit=10)

    return render_template(
        "account.html",
        user_id=user[0],
        full_name=user[1],
        email=user[2],
        mobile=user[3],
        referral=user[5],
        inr_balance=user[6],
        mrx_balance=0,
        created_at=datetime.fromtimestamp(int(user[8])).strftime('%Y-%m-%d %H:%M:%S'),
        transactions=transactions
    )

@app.route("/accelerate")
def accelerate_page():
    if "user" not in session:
        return redirect(url_for("login"))

    user = get_user(session["user"])
    if not user:
        return redirect(url_for("logout"))

    return render_template("accelerate.html")

@app.route("/deposit")
def deposit():
    if "user" not in session:
        return redirect(url_for("login"))

    user = get_user(session["user"])
    if not user:
        return redirect(url_for("logout"))

    return render_template("deposit.html")

@app.route("/withdraw")
def withdraw():
    if "user" not in session:
        return redirect(url_for("login"))

    user = get_user(session["user"])
    if not user:
        return redirect(url_for("logout"))

    return render_template(
        "withdraw.html",
        user_name=user[1],
        inr_balance=user[6],
        mrx_balance=0
    )

# -------------------------
# ADMIN ROUTES
# -------------------------
@app.route("/admin")
def admin_dashboard():
    if "user" not in session:
        return redirect(url_for("login"))

    if not is_admin(session["user"]):
        return "Access denied", 403

    return render_template("admin/dashboard.html")

@app.route("/admin/deposits")
def admin_deposits():
    if "user" not in session:
        return redirect(url_for("login"))

    if not is_admin(session["user"]):
        return "Access denied", 403

    return render_template("admin/admin_deposit.html")

@app.route("/admin/withdrawals")
def admin_withdrawals():
    if "user" not in session:
        return redirect(url_for("login"))

    if not is_admin(session["user"]):
        return "Access denied", 403

    return render_template("admin/admin_withdrawal.html")

@app.route("/admin/users")
def admin_users():
    if "user" not in session:
        return redirect(url_for("login"))

    if not is_admin(session["user"]):
        return "Access denied", 403

    return render_template("admin/users.html")

@app.route("/admin/logs")
def admin_logs():
    if "user" not in session:
        return redirect(url_for("login"))

    if not is_admin(session["user"]):
        return "Access denied", 403

    return render_template("admin/logs.html")

@app.route("/admin/tax")
def admin_tax():
    if "user" not in session:
        return redirect(url_for("login"))

    if not is_admin(session["user"]):
        return "Access denied", 403

    return render_template("admin/tax.html")

# NEW: Admin daily limits page
@app.route("/admin/daily-limits")
def admin_daily_limits():
    if "user" not in session:
        return redirect(url_for("login"))

    if not is_admin(session["user"]):
        return "Access denied", 403

    return render_template("admin/daily_limits.html")

# -------------------------
# DEBUG ENDPOINTS
# -------------------------
@app.route("/debug")
def debug():
    ensure_files()

    status = {
        "session_user": session.get("user", "Not logged in"),
        "files_exist": {
            "users.tsv": os.path.exists(USERS_FILE),
            "market.tsv": os.path.exists(MARKET_FILE),
            "tax_collection.tsv": os.path.exists(TAX_COLLECTION_FILE),
            "orders.tsv": os.path.exists(ORDERS_FILE),
            "internal_mrx.tsv": os.path.exists(INTERNAL_MRX_FILE),
            "daily_trades.tsv": os.path.exists(DAILY_TRADES_FILE),
            "data_dir": os.path.exists(DATA_DIR)
        },
        "user_count": 0,
        "admin_users": []
    }

    if os.path.exists(USERS_FILE):
        with open(USERS_FILE, "r") as f:
            lines = f.readlines()
            status["user_count"] = len(lines) - 1

            for i, line in enumerate(lines):
                if i == 0:
                    continue
                cols = line.strip().split("\t")
                if len(cols) >= 3 and ('admin' in cols[2].lower() or cols[0].startswith('ADM')):
                    status["admin_users"].append({
                        "email": cols[2],
                        "user_id": cols[0],
                        "is_admin_by_email": 'admin' in cols[2].lower(),
                        "is_admin_by_id": cols[0].startswith('ADM')
                    })

    return jsonify(status)

@app.route("/debug/profit-calculation")
def debug_profit_calculation():
    # Example with corrected calculation
    investment = 100.00
    price_before = 1.00
    tax_rate = 0.05
    tax = investment * tax_rate
    amount_to_pool = investment - tax
    mrx_allocated = amount_to_pool / price_before

    inr_pool_before = 2000.00
    mrx_pool_before = 1000.00

    new_inr_pool = inr_pool_before + amount_to_pool
    new_mrx_pool = mrx_pool_before - mrx_allocated
    new_price = new_inr_pool / new_mrx_pool

    mrx_value = mrx_allocated * new_price
    profit = mrx_value - investment

    # User wallet calculation
    user_balance_before = 1000.00
    user_balance_after = user_balance_before - investment + mrx_value

    html = f"""
    <h1>Corrected Profit & Wallet Calculation</h1>
    <h2>Example: ₹{investment:.2f} investment at ₹{price_before:.2f}/MRX</h2>

    <h3>Step 1: Tax Calculation</h3>
    <ul>
        <li>Investment: ₹{investment:.2f}</li>
        <li>Tax ({tax_rate*100}%): ₹{tax:.2f}</li>
        <li>Amount to pool: ₹{amount_to_pool:.2f}</li>
    </ul>

    <h3>Step 2: MRX Allocation</h3>
    <ul>
        <li>MRX allocated: {amount_to_pool:.2f} / {price_before:.2f} = {mrx_allocated:.6f} MRX</li>
    </ul>

    <h3>Step 3: Pool Update</h3>
    <ul>
        <li>INR pool: {inr_pool_before:.2f} + {amount_to_pool:.2f} = ₹{new_inr_pool:.2f}</li>
        <li>MRX pool: {mrx_pool_before:.6f} - {mrx_allocated:.6f} = {new_mrx_pool:.6f} MRX</li>
        <li>New price: {new_inr_pool:.2f} / {new_mrx_pool:.6f} = ₹{new_price:.4f}</li>
    </ul>

    <h3>Step 4: User Wallet Calculation</h3>
    <ul>
        <li>User starts with: ₹{user_balance_before:.2f}</li>
        <li>MRX value at new price: {mrx_allocated:.6f} × ₹{new_price:.4f} = ₹{mrx_value:.2f}</li>
        <li>User wallet after: ₹{user_balance_before:.2f} - ₹{investment:.2f} + ₹{mrx_value:.2f} = <strong>₹{user_balance_after:.2f}</strong></li>
        <li><strong>Profit:</strong> ₹{user_balance_after:.2f} - ₹{user_balance_before:.2f} = ₹{profit:.2f}</li>
    </ul>

    <p><strong>Key Point:</strong> User sees MRX value as INR in their wallet immediately.</p>

    <p><a href="/">Back to home</a></p>
    """

    return html

# -------------------------
# ERROR HANDLERS
# -------------------------
@app.errorhandler(404)
def not_found(error):
    return jsonify({"success": False, "error": "Not found"}), 404

@app.errorhandler(500)
def server_error(error):
    return jsonify({"success": False, "error": "Internal server error"}), 500

# -------------------------
# START SERVER
# -------------------------
if __name__ == "__main__":
    ensure_files()

    print("=" * 80)
    print("UNITED WORLD - ENHANCED WITHDRAWAL & DAILY LIMIT SYSTEM")
    print("=" * 80)
    print("🔄 UPDATED WITHDRAWAL FLOW:")
    print("   1. User requests withdrawal → Amount DEDUCTED immediately")
    print("   2. Admin approves → Market updated (MRX sold from internal balance)")
    print("   3. Admin rejects → Amount REFUNDED to user wallet")
    print("   ⚠️ Price changes ONLY on admin approval")
    print()
    print("💰 DAILY TRADING LIMIT:")
    print(f"   • ₹{DAILY_TRADING_LIMIT:.2f} maximum per user per day")
    print("   • Resets automatically at midnight")
    print("   • Tracks all buy orders (accelerate-order endpoint)")
    print()
    print("🛡️ INR POOL PROTECTION:")
    print(f"   • Minimum INR pool: ₹{MIN_INR_POOL:.2f}")
    print("   • Withdrawals blocked if would bring pool below minimum")
    print("   • Checks at request submission AND admin approval")
    print()
    print("📊 WALLET DISPLAY LOGIC:")
    print("   User's wallet shows: remaining INR + current MRX value")
    print("   Formula: new_inr = old_inr - invested + (MRX × new_price)")
    print("=" * 80)
    print("🔒 SECURITY FEATURES:")
    print("   • Price floor: ₹1.00 per MRX (cannot go below)")
    print(f"   • INR pool minimum: ₹{MIN_INR_POOL:.2f}")
    print("   • Tax: 5% ONLY on BUY orders")
    print("   • NO tax on withdrawals")
    print("   • Maximum single order: ₹1,000")
    print("=" * 80)
    
    import os
    port = int(os.environ.get("PORT", 10000))
    
    print(f"🚀 Starting server on http://0.0.0.0:{port}")
    print("=" * 80)

    app.run(host="0.0.0.0", port=port)
