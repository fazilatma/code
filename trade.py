# ================================================================================
# بخش 1: IMPORTS و CONFIGURATIONS
# ================================================================================
import os
import time
import requests
import json
import pandas as pd
import numpy as np
from flask import Flask, render_template_string, jsonify, request

app = Flask(__name__)

# --- CONFIGURATION V6.9.0 ---
VERSION = "6.9.0"
NOBITEX_TOKEN = os.environ.get("NOBITEX_TOKEN", "")
CONFIG_FILE = "bot_config.json"
LIVE_LOG_FILE = "live_trades_log.csv"
SIM_LOG_FILE = "sim_trades_log.csv"

# ================================================================================
# بخش 2: DEFAULT STRATEGY CODE (PINE SCRIPT STYLE)
# ================================================================================
########## ENGINE: STRATEGY LOGIC (PINE-SCRIPT STYLE) ##########
# AVAILABLE VARIABLES:
#   - close: pandas Series of close prices (most recent last)
#   - super_line: pandas Series of SuperTrend values
#   - current_steps: list of bought prices (in Toman)
#   - n_max: max number of steps (from slider)
#   - gap_pct: min gap (%) between steps (from slider)
#   - tp_pct: take-profit (%) for SELL_ALL (from slider)
# AVAILABLE FUNCTIONS:
#   - crossover(s1, s2) → bool
#   - crossunder(s1, s2) → bool
#   - calc_avg(lst) → float

DEFAULT_CODE = """
action = None
avg_p = calc_avg(current_steps)
target_p = avg_p * (1 + tp_pct) if avg_p > 0 else 0
last_p = close.iloc[-1]

# 1. BUY: on crossover & below gap threshold
if crossover(close, super_line) and len(current_steps) < n_max:
    if not current_steps or last_p <= current_steps[-1] * (1 - gap_pct):
        action = "BUY"

# 2. SELL_LAST: on crossunder when at full capacity
elif len(current_steps) == n_max and crossunder(close, super_line):
    action = "SELL_LAST"

# 3. SELL_ALL: on crossunder when in profit
elif current_steps and last_p > target_p and crossunder(close, super_line):
    action = "SELL_ALL"
"""

################################################################

# ================================================================================
# بخش 3: CORE LOGIC FUNCTIONS (CALCULATIONS)
# ================================================================================

def get_balance():
    try:
        url = 'https://apiv2.nobitex.ir/users/wallets/list?type=spot'
        headers = {"Authorization": f"Token {NOBITEX_TOKEN}"}
        res = requests.post(url, headers=headers, json={"currency": "rls"}, timeout=5)
        data = res.json()
        if data.get('status') == 'ok':
            toman_balance = 0
            usdt_balance = 0
            for w in data['wallets']:
                if w['currency'] == 'rls':
                    toman_balance = float(w['balance']) / 10  # Rial to Toman
                elif w['currency'] == 'usdt':
                    usdt_balance = float(w['balance'])
        else:
            return 0, 0, data.get('message', 'خطا در دریافت موجودی')
        return toman_balance, usdt_balance, None
    except Exception as e:
        return 0, 0, str(e)

def get_usd_to_irt_rate():
    try:
        # از API نوبیتکس v3 برای گرفتن قیمت USDT/IRT استفاده می‌کنیم
        res = requests.get('https://apiv2.nobitex.ir/v3/orderbook/USDTIRT', timeout=5)
        data = res.json()
        # استفاده از lastTradePrice (در ریال، تقسیم بر 10 برای تومان)
        if 'lastTradePrice' in data:
            return float(data['lastTradePrice']) / 10
        else:
            return 53000  # قیمت پیش‌فرض در صورت نبود (تومان)
    except:
        return 53000  # قیمت پیش‌فرض در صورت خطا (تومان)

def place_order(side, amount_rls):
    try:
        url = 'https://apiv2.nobitex.ir/market/orders/add'
        payload = {
            "type": side,
            "srcCurrency": "usdt",
            "dstCurrency": "rls",
            "execution": "market",
            "amount": str(amount_rls),
            "clientOrderId": f"bot_{int(time.time())}"
        }
        headers = {
            "Authorization": f"Token {NOBITEX_TOKEN}",
            "Content-Type": "application/json"
        }
        res = requests.post(url, headers=headers, json=payload, timeout=10)
        return res.json()
    except Exception as e:
        return {"error": str(e)}

def calculate_st(df, p, m):
    if len(df) < p:
        df['super_line'] = 0.0
        df['dir'] = 1
        return df

    hl2 = (df['high'] + df['low']) / 2
    df['tr'] = np.maximum(
        df['high'] - df['low'],
        np.maximum(
            np.abs(df['high'] - df['close'].shift(1)),
            np.abs(df['low'] - df['close'].shift(1))
        )
    )
    atr = df['tr'].rolling(window=p, min_periods=1).mean()
    up = hl2 + (m * atr)
    dn = hl2 - (m * atr)

    st = np.full(len(df), np.nan)
    direction = np.ones(len(df), dtype=int)
    st[0] = dn.iloc[0] if df['close'].iloc[0] > up.iloc[0] else up.iloc[0]

    for i in range(1, len(df)):
        if df['close'].iloc[i] > up.iloc[i-1]:
            direction[i] = 1
        elif df['close'].iloc[i] < dn.iloc[i-1]:
            direction[i] = -1
        else:
            direction[i] = direction[i-1]
            if direction[i] == 1 and dn.iloc[i] < dn.iloc[i-1]:
                dn.iloc[i] = dn.iloc[i-1]
            if direction[i] == -1 and up.iloc[i] > up.iloc[i-1]:
                up.iloc[i] = up.iloc[i-1]
        st[i] = dn.iloc[i] if direction[i] == 1 else up.iloc[i]

    df['super_line'] = pd.Series(st).fillna(0).values
    df['dir'] = direction
    return df

def crossover(s1, s2):
    if len(s1) < 2: return False
    return (s1.iloc[-2] <= s2.iloc[-2]) and (s1.iloc[-1] > s2.iloc[-1])

def crossunder(s1, s2):
    if len(s1) < 2: return False
    return (s1.iloc[-2] >= s2.iloc[-2]) and (s1.iloc[-1] < s2.iloc[-1])

def calc_avg(steps): return sum(steps)/len(steps) if steps else 0.0

# ================================================================================
# بخش 4: FLASK ROUTES (به‌روزرسانی شده)
# ================================================================================

@app.route('/')
def index():
    cfg = {
        "h": 720, "p": 7, "m": 3.0, "n": 5, "g": 1.0, "t": 1.5,
        "tf": "60", "fetch": 10, "chartLib": "tradingview", "code": DEFAULT_CODE, "mode": "sim"
    }
    if os.path.exists(CONFIG_FILE):
        try:
            with open(CONFIG_FILE, 'r', encoding='utf-8') as f:
                loaded = json.load(f)
                cfg.update(loaded)
        except:
            pass
    return render_template_string(HTML_UI, cfg=cfg, version=VERSION)

@app.route('/api/save_config', methods=['POST'])
def save_cfg():
    try:
        with open(CONFIG_FILE, 'w', encoding='utf-8') as f:
            json.dump(request.json, f, ensure_ascii=False, indent=2)
        return jsonify({"status": "ok"})
    except Exception as e:
        return jsonify({"error": str(e)})

@app.route('/api/reset')
def reset():
    try:
        mode = request.args.get('mode', 'sim')
        file = LIVE_LOG_FILE if mode == "live" else SIM_LOG_FILE
        if os.path.exists(file):
            os.remove(file)
        return jsonify({"status": "ok"})
    except Exception as e:
        return jsonify({"error": str(e)})

@app.route('/api/process', methods=['POST'])
def process():
    try:
        req = request.json
        mode = req.get('mode', 'sim')
        log_file = LIVE_LOG_FILE if mode == "live" else SIM_LOG_FILE
        h = int(req['h'])
        p = int(req['p'])
        m = float(req['m'])
        n_max = int(req['n'])
        gap_pct = float(req['g']) / 100.0
        tp_pct = float(req['t']) / 100.0
        tf_sec = int(req['tf'])

        # Convert tf to minutes for Nobitex (they use minutes)
        tf_map = {
            "60": "1", "300": "5", "900": "15", "1800": "30",
            "3600": "60", "7200": "120", "14400": "240", "21600": "360",
            "43200": "720", "86400": "1440"
        }
        tf_nobitex = tf_map.get(str(tf_sec), "1")

        # Fetch data
        now = int(time.time())
        res = requests.get(
            f"https://apiv2.nobitex.ir/market/udf/history?symbol=USDTIRT&resolution={tf_nobitex}&from={now-(h*60)}&to={now}",
            timeout=10
        ).json()

        if res.get('s') != 'ok':
            return jsonify({"error": "داده‌های تاریخی دریافت نشدند"})

        df = pd.DataFrame({
            'time': res['t'],
            'high': res['h'],
            'low': res['l'],
            'close': res['c']
        }).astype({'time': int, 'high': float, 'low': float, 'close': float})

        df = calculate_st(df, p, m)
        last_p = df['close'].iloc[-1]

        # Get balances
        toman_bal, usdt_bal, bal_err = get_balance()
        usd_rate = get_usd_to_irt_rate()
        total_balance_irt = toman_bal + (usdt_bal * usd_rate)

        if bal_err:
            bal_html = f"<span style='color:#ff6b6b'>❌ {bal_err}</span>"
        else:
            bal_html = f"""
            <div style='text-align: right;'>
                <strong>موجودی تومانی:</strong> {int(toman_bal):,} تومان<br>
                <strong>موجودی دلاری:</strong> {usdt_bal:.4f} دلار<br>
                <strong>نرخ دلار:</strong> {int(usd_rate):,} تومان<br>
                <strong>موجودی کل:</strong> {int(total_balance_irt):,} تومان
            </div>
            """

        # Calculate step size (min 50,000 Toman)
        step_toman = max(50000, total_balance_irt / n_max) if total_balance_irt > 0 else 0
        step_usd = step_toman / usd_rate if usd_rate > 0 else 0

        # --- LIVE/SIM STATUS ---
        if not os.path.exists(log_file):
            pd.DataFrame(columns=['Price']).to_csv(log_file, index=False)
        live_steps = pd.read_csv(log_file)['Price'].tolist()
        avg_l = calc_avg(live_steps)
        l_pnl_t = (last_p - avg_l) * (len(live_steps) * step_toman / (avg_l or 1)) if avg_l else 0
        l_pnl_p = ((last_p / avg_l) - 1) * 100 if avg_l else 0

        # --- BACKTEST (1,000,000 Toman capital) ---
        bt_capital = 1_000_000
        bt_steps, bt_log, bt_realized_pnl = [], [], 0
        buy_x, buy_y, sell_x, sell_y = [], [], [], []

        for i in range(max(p, 2), len(df)):
            scope = df.iloc[:i+1]
            loc = {
                'close': scope['close'],
                'super_line': scope['super_line'],
                'current_steps': bt_steps[:],
                'n_max': n_max,
                'gap_pct': gap_pct,
                'tp_pct': tp_pct,
                'action': None,
                'crossover': crossover,
                'crossunder': crossunder,
                'calc_avg': calc_avg
            }
            try:
                exec(req['code'], {}, loc)
                act = loc['action']
                cp = df['close'].iloc[i]

                if act == "BUY" and len(bt_steps) < n_max:
                    bt_steps.append(cp)
                    buy_x.append(i)
                    buy_y.append(cp)

                elif act == "SELL_ALL" and bt_steps:
                    avg_bt = calc_avg(bt_steps)
                    qty = len(bt_steps) * (bt_capital / n_max) / avg_bt
                    pnl = (cp - avg_bt) * qty
                    bt_realized_pnl += pnl
                    bt_log.append(f"✅ فروش کل در {int(cp):,} | سود: {int(pnl):+,} تومان")
                    bt_steps = []
                    sell_x.append(i)
                    sell_y.append(cp)

                elif act == "SELL_LAST" and bt_steps:
                    bt_log.append(f"⚠️ تخلیه پله آخر در {int(cp):,}")
                    bt_steps.pop()
                    sell_x.append(i)
                    sell_y.append(cp)
            except Exception as e:
                return jsonify({"error": f"خطا در استراتژی: {str(e)}"})

        # Open PnL for backtest
        bt_open_pnl_t, bt_open_pnl_p = 0, 0
        if bt_steps:
            avg_bt = calc_avg(bt_steps)
            qty = len(bt_steps) * (bt_capital / n_max) / avg_bt
            bt_open_pnl_t = (last_p - avg_bt) * qty
            bt_open_pnl_p = ((last_p / avg_bt) - 1) * 100

        # Prepare chart data (replace NaN/inf with None for JSON)
        df_clean = df.replace([np.inf, -np.inf], np.nan).fillna(0)
        chart_x = df_clean['time'].tolist()
        chart_y = [float(x) if pd.notna(x) else None for x in df_clean['close']]
        st_vals = [float(x) if pd.notna(x) and x != 0 else None for x in df_clean['super_line']]
        dir_vals = df_clean['dir'].tolist()

        # Lines for chart: if live steps exist, show live lines; else show backtest lines
        has_live = len(live_steps) > 0
        lines = {
            "avg": avg_l if has_live and avg_l > 0 else (calc_avg(bt_steps) if bt_steps else 0),
            "target": avg_l * (1 + tp_pct) if has_live and avg_l > 0 else (calc_avg(bt_steps) * (1 + tp_pct) if bt_steps else 0),
            "gap": (live_steps[-1] * (1 - gap_pct)) if has_live and live_steps else (bt_steps[-1] * (1 - gap_pct) if bt_steps else 0)
        }

        return jsonify({
            "bal_html": bal_html,
            "step_html": f"{step_usd:.4f} $ ({int(step_toman):,} تومان)",
            "live_steps": f"{len(live_steps)} / {n_max}",
            "pnl_html": f"<b style='color:{'#2ecc71' if l_pnl_t >= 0 else '#e74c3c'}'>{int(l_pnl_t):+,} تومان</b> ({l_pnl_p:.2f}%)",
            "bt_report": f"سود/زیان معاملات بسته: {int(bt_realized_pnl):+,} تومان",
            "bt_open_info": f"PNL پله‌های باز: {int(bt_open_pnl_t):+,} تومان ({bt_open_pnl_p:.2f}%) | <b>تعداد پله باز: {len(bt_steps)}</b>",
            "bt_open_list": "".join([f"<li>پله {idx+1}: {int(p):,} تومان</li>" for idx, p in enumerate(bt_steps)]) or "پله بازی وجود ندارد",
            "bt_history": "<br>".join(bt_log[::-1]) if bt_log else "تاریخچه‌ای وجود ندارد",
            "chart": {
                "x": chart_x,
                "y": chart_y,
                "st": st_vals,
                "dir": dir_vals,
                "bx": buy_x,
                "by": buy_y,
                "sx": sell_x,
                "sy": sell_y
            },
            "lines": lines
        })
    except Exception as e:
        return jsonify({"error": str(e)})

@app.route('/api/execute_order', methods=['POST'])
def execute_order():
    try:
        data = request.json
        side = data.get('side', '').lower()
        amount = data.get('amount', 0)
        mode = data.get('mode', 'sim')

        if side not in ['buy', 'sell']:
            return jsonify({"error": "side must be 'buy' or 'sell'"})

        log_file = LIVE_LOG_FILE if mode == "live" else SIM_LOG_FILE

        if mode == "live":
            # اجرای واقعی
            if side == 'buy':
                toman_bal, usdt_bal, err = get_balance()
                if err:
                    return jsonify({"error": f"خطا در دریافت موجودی: {err}"})
                total_bal_irt = toman_bal + (usdt_bal * get_usd_to_irt_rate())
                if amount < 50000:
                    return jsonify({"error": "مبلغ خرید باید حداقل 50,000 تومان باشد."})
                price_per_usdt = get_usd_to_irt_rate()
                amount_rls = amount / price_per_usdt
                order_data = place_order('buy', amount_rls)
            else:
                order_data = place_order('sell', amount)

            if 'error' in order_data:
                return jsonify({"error": order_data['error']})
            else:
                if side == 'buy':
                    new_row = pd.DataFrame({'Price': [amount]})
                    if os.path.exists(log_file):
                        df_log = pd.read_csv(log_file)
                        df_log = pd.concat([df_log, new_row], ignore_index=True)
                    else:
                        df_log = new_row
                    df_log.to_csv(log_file, index=False)
                return jsonify({"status": "ok", "data": order_data})
        else:
            # حالت شبیه‌سازی
            if side == 'buy':
                new_row = pd.DataFrame({'Price': [amount]})
                if os.path.exists(log_file):
                    df_log = pd.read_csv(log_file)
                    df_log = pd.concat([df_log, new_row], ignore_index=True)
                else:
                    df_log = new_row
                df_log.to_csv(log_file, index=False)
            return jsonify({"status": "ok", "data": {"message": "Order executed in simulation mode"}})
    except Exception as e:
        return jsonify({"error": str(e)})

# ================================================================================
# بخش 5: HTML/CSS/JS UI (واکنش‌گرا)
# ================================================================================

HTML_UI = """
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🤖 ترید هوشمند نوبیتکس V{{version}}</title>
    <script src="https://cdn.plot.ly/plotly-latest.min.js"></script>
    <script src="https://unpkg.com/lightweight-charts@4.1.1/dist/lightweight-charts.standalone.production.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Vazirmatn', Tahoma, sans-serif;
            background: linear-gradient(135deg, #0f2027, #203a43, #2c5364);
            color: #ecf0f1;
            display: flex;
            height: 100vh;
            overflow: hidden;
        }
        .sidebar {
            width: 400px;
            background: rgba(25, 42, 50, 0.95);
            backdrop-filter: blur(10px);
            padding: 25px;
            border-right: 2px solid #1abc9c;
            overflow-y: auto;
            box-shadow: 0 0 20px rgba(0,0,0,0.5);
        }
        .main { flex-grow: 1; position: relative; }
        .section {
            background: rgba(30, 47, 60, 0.7);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #1abc9c;
        }
        .section-title {
            color: #1abc9c;
            font-weight: bold;
            font-size: 22px;
            display: block;
            margin-bottom: 16px;
            text-align: center;
            background: rgba(26, 188, 156, 0.1);
            padding: 10px;
            border-radius: 8px;
        }
        label {
            display: flex;
            justify-content: space-between;
            font-size: 17px;
            margin-bottom: 10px;
            color: #bdc3c7;
        }
        input[type=range] {
            width: 100%;
            height: 26px;
            -webkit-appearance: none;
            background: #2c3e50;
            outline: none;
            border-radius: 10px;
            margin-top: 8px;
        }
        input[type=range]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 24px;
            height: 24px;
            background: #1abc9c;
            border-radius: 50%;
            cursor: pointer;
        }
        select {
            width: 100%;
            padding: 12px;
            background: #2c3e50;
            color: #ecf0f1;
            border: 1px solid #1abc9c;
            border-radius: 8px;
            font-size: 17px;
            margin-top: 8px;
        }
        .btn {
            width: 100%;
            padding: 14px;
            cursor: pointer;
            font-weight: bold;
            border-radius: 8px;
            border: none;
            margin-top: 15px;
            font-size: 17px;
            transition: all 0.3s ease;
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.3); }
        .btn-save { background: linear-gradient(to right, #2ecc71, #27ae60); color: white; }
        .btn-reset { background: linear-gradient(to right, #e74c3c, #c0392b); color: white; }
        .btn-edit { background: linear-gradient(to right, #3498db, #2980b9); color: white; }
        .btn-trade { background: linear-gradient(to right, #9b59b6, #8e44ad); color: white; }
        .btn-mode { background: linear-gradient(to right, #f39c12, #e67e22); color: white; }
        #code-wrap {
            position: absolute;
            bottom: 0;
            width: 100%;
            height: 400px;
            background: rgba(10, 20, 30, 0.98);
            backdrop-filter: blur(10px);
            border-top: 3px solid #1abc9c;
            display: none;
            flex-direction: column;
            z-index: 100;
            padding: 20px;
        }
        textarea {
            flex-grow: 1;
            background: #1a242f;
            color: #7fdbda;
            font-family: monospace;
            padding: 15px;
            font-size: 15px;
            border-radius: 8px;
            border: 1px solid #1abc9c;
        }
        .collapse-box {
            display: none;
            background: rgba(20, 30, 40, 0.9);
            padding: 15px;
            font-size: 14px;
            margin-top: 10px;
            border: 1px solid #3498db;
            border-radius: 8px;
            max-height: 200px;
            overflow-y: auto;
            line-height: 1.6;
        }
        .stat-box {
            background: rgba(44, 62, 80, 0.8);
            padding: 15px;
            border-radius: 10px;
            margin: 10px 0;
            font-size: 18px;
            border: 1px dashed #1abc9c;
        }
        .highlight { color: #f1c40f; font-weight: bold; }
        .profit { color: #2ecc71; }
        .loss { color: #e74c3c; }
        .error { color: #ff6b6b; }
        .mode-indicator {
            text-align: center;
            padding: 10px;
            font-weight: bold;
            font-size: 18px;
            margin-bottom: 15px;
            border-radius: 8px;
        }
        .live-mode { background: rgba(231, 76, 60, 0.2); border: 1px solid #e74c3c; color: #e74c3c; }
        .sim-mode { background: rgba(52, 152, 219, 0.2); border: 1px solid #3498db; color: #3498db; }

        /* Responsive Design */
        @media screen and (max-width: 768px) {
            body {
                flex-direction: column;
                height: auto;
                overflow-x: hidden;
            }
            .sidebar {
                width: 100%;
                height: auto;
                max-height: 50vh;
                overflow-y: auto;
                border-right: none;
                border-bottom: 2px solid #1abc9c;
            }
            .main {
                height: 50vh;
            }
            .section {
                padding: 15px;
                margin-bottom: 15px;
            }
            .btn {
                padding: 12px;
                font-size: 16px;
                margin-top: 12px;
            }
            .section-title {
                font-size: 18px;
            }
            label {
                font-size: 15px;
            }
            select, input[type="range"] {
                font-size: 15px;
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="mode-indicator live-mode" id="mode-indicator">
            🚨 حالت لایو (ارسال واقعی سفارش)
        </div>
        <div class="section">
            <span class="section-title">💰 وضعیت مالی لحظه‌ای</span>
            <div class="stat-box">
                <span id="bal-val">...</span><br>
                <strong>اندازه هر پله:</strong> <span id="step-val">...</span><br>
                <strong>پله‌های لایو:</strong> <span id="live-steps" class="highlight">0/0</span><br>
                <strong>PNL کل:</strong> <span id="pnl-val">0</span>
            </div>
        </div>
        <div class="section">
            <span class="section-title">⏱️ تنظیمات زمانی</span>
            <label>تایم‌فریم:</label>
            <select id="tf">
                <option value="60" {% if cfg.tf=="60" %}selected{% endif %}>1 دقیقه</option>
                <option value="300" {% if cfg.tf=="300" %}selected{% endif %}>5 دقیقه</option>
                <option value="900" {% if cfg.tf=="900" %}selected{% endif %}>15 دقیقه</option>
                <option value="1800" {% if cfg.tf=="1800" %}selected{% endif %}>30 دقیقه</option>
                <option value="3600" {% if cfg.tf=="3600" %}selected{% endif %}>1 ساعت</option>
                <option value="7200" {% if cfg.tf=="7200" %}selected{% endif %}>2 ساعت</option>
                <option value="14400" {% if cfg.tf=="14400" %}selected{% endif %}>4 ساعت</option>
                <option value="21600" {% if cfg.tf=="21600" %}selected{% endif %}>6 ساعت</option>
                <option value="43200" {% if cfg.tf=="43200" %}selected{% endif %}>12 ساعت</option>
                <option value="86400" {% if cfg.tf=="86400" %}selected{% endif %}>1 روز</option>
            </select>
            <label>کتابخانه نمودار:</label>
            <select id="chartLib">
                <option value="plotly" {% if cfg.chartLib=="plotly" %}selected{% endif %}>Plotly</option>
                <option value="tradingview" {% if cfg.chartLib=="tradingview" %}selected{% endif %}>TradingView</option>
                <option value="chartjs" {% if cfg.chartLib=="chartjs" %}selected{% endif %}>Chart.js</option>
                <option value="apexcharts" {% if cfg.chartLib=="apexcharts" %}selected{% endif %}>ApexCharts</option>
            </select>
            <label>تاریخچه (دقیقه): <span id="h-v">{{cfg.h}}</span></label>
            <input type="range" id="h" min="60" max="2880" value="{{cfg.h}}">
            <label>فواصل واکشی (ثانیه): <span id="fetch-v">{{cfg.fetch}}</span></label>
            <input type="range" id="fetch" min="5" max="120" step="5" value="{{cfg.fetch}}">
        </div>
        <div class="section">
            <span class="section-title">📊 پارامترهای سوپرترند</span>
            <label>دوره سوپرترند: <span id="p-v">{{cfg.p}}</span></label>
            <input type="range" id="p" min="1" max="50" value="{{cfg.p}}">
            <label>ضریب سوپرترند: <span id="m-v">{{cfg.m}}</span></label>
            <input type="range" id="m" min="0.5" max="10" step="0.5" value="{{cfg.m}}">
            <button class="btn" style="background:#e67e22; margin-top:15px;" onclick="toggleDottedLines()">👁️ تاگل نمایش خطوط نقطه‌چین</button>
        </div>
        <div class="section">
            <span class="section-title">⚖️ مدیریت سرمایه</span>
            <label>تعداد پله (n): <span id="n-v">{{cfg.n}}</span></label>
            <input type="range" id="n" min="1" max="25" value="{{cfg.n}}">
            <label>فاصله خرید بعدی (%): <span id="g-v">{{cfg.g}}</span></label>
            <input type="range" id="g" min="0.1" max="10" step="0.1" value="{{cfg.g}}">
            <label>سود فروش کل (%): <span id="t-v">{{cfg.t}}</span></label>
            <input type="range" id="t" min="0.1" max="15" step="0.1" value="{{cfg.t}}">
        </div>
        <div class="section">
            <span class="section-title">📉 گزارش بک‌تست</span>
            <div id="bt-rep" style="color:#2ecc71; font-weight:bold; font-size:15px;"></div>
            <div id="bt-open-info" style="color:#f1c40f; font-size:14px; margin-top:8px;"></div>
            <button class="btn" style="background:#3498db;" onclick="toggle('box-open')">📂 پله‌های باز</button>
            <div id="box-open" class="collapse-box"><ul id="bt-open-list"></ul></div>
            <button class="btn" style="background:#9b59b6;" onclick="toggle('box-history')">📋 تاریخچه معاملات</button>
            <div id="box-history" class="collapse-box"></div>
        </div>
        <button class="btn btn-mode" onclick="toggleMode()">🔄 تغییر حالت (لایو/شبیه‌سازی)</button>
        <button class="btn btn-trade" onclick="manualBuy()">🛒 خرید دستی یک پله</button>
        <button class="btn btn-trade" onclick="manualSellAll()">📤 فروش همه پله‌ها</button>
        <button class="btn btn-save" onclick="save()">💾 ذخیره تنظیمات</button>
        <button class="btn btn-reset" onclick="reset()">🔄 ریست پله‌ها</button>
    </div>
    <div class="main">
        <div id="chart" style="width:100%; height:100%;"></div>
        <button class="btn btn-edit" style="position:absolute; bottom:25px; left:25px; width:auto; padding:12px 25px;" onclick="toggle('code-wrap')">📝 ویرایش کد استراتژی</button>
        <div id="code-wrap">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                <span style="font-weight:bold; color:#1abc9c; font-size:18px;">📝 کد استراتژی (Pine Style)</span>
                <button onclick="update()">🔄 اجرا</button>
            </div>
            <textarea id="code">{{cfg.code}}</textarea>
        </div>
    </div>
    <script>
        let autoInterval = null;
        let currentMode = "{{cfg.mode}}"; // 'live' یا 'sim'
        let showDottedLines = true; // وضعیت نمایش خطوط نقطه‌چین
        // Keep a reference to the created TradingView (lightweight) chart
        // so we can preserve its visible time range (zoom/pan) across updates.
        let tvChart = null;

        function toggle(id) { 
            const e = document.getElementById(id); 
            e.style.display = (e.style.display === 'block' || e.style.display === 'flex') ? 'none' : (id === 'code-wrap' ? 'flex' : 'block'); 
        }

        function toggleDottedLines() {
            showDottedLines = !showDottedLines;
            const btn = document.querySelector('button[onclick="toggleDottedLines()"]');
            btn.innerText = showDottedLines ? '👁️ تاگل نمایش خطوط نقطه‌چین' : '🙈 تاگل نمایش خطوط نقطه‌چین';
            update(); // بروزرسانی نمودار
        }

        function getParams() { 
            return { 
                h: document.getElementById('h').value,
                p: document.getElementById('p').value,
                m: document.getElementById('m').value,
                n: document.getElementById('n').value,
                g: document.getElementById('g').value,
                t: document.getElementById('t').value,
                tf: document.getElementById('tf').value,
                chartLib: document.getElementById('chartLib').value,
                fetch: document.getElementById('fetch').value,
                code: document.getElementById('code').value,
                mode: currentMode
            }; 
        }

        function toggleMode() {
            currentMode = currentMode === 'live' ? 'sim' : 'live';
            document.getElementById('mode-indicator').className = 'mode-indicator ' + (currentMode === 'live' ? 'live-mode' : 'sim-mode');
            document.getElementById('mode-indicator').innerText = currentMode === 'live' ? '🚨 حالت لایو (ارسال واقعی سفارش)' : '🎮 حالت شبیه‌سازی (بدون ارسال سفارش)';
            update();
        }

        function save() {
            const params = getParams();
            fetch('/api/save_config', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(params)
            }).then(r => r.json()).then(d => {
                if(d.error) alert('❌ خطا: ' + d.error);
                else alert('✅ تنظیمات ذخیره شد.');
            });
        }

        function reset() {
            if(!confirm("⚠️ آیا مطمئن هستید؟ تمام پله‌های " + (currentMode === 'live' ? 'لایو' : 'شبیه‌سازی') + " حذف می‌شوند.")) return;
            fetch('/api/reset?mode=' + currentMode).then(() => update());
        }

        function manualBuy() {
            const p = getParams();
            fetch('/api/process', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({...p, h:1, tf:'60'})
            }).then(r => r.json()).then(data => {
                if(data.error) {
                    alert('❌ خطا: ' + data.error);
                    return;
                }
                const lastPrice = data.chart.y[data.chart.y.length - 1];
                if (!lastPrice) {
                    alert('❌ نمی‌توان قیمت آخر را گرفت.');
                    return;
                }
                const stepToman = parseInt(document.getElementById('step-val').innerText.match(/\\d+,?\\d*/)?.[0].replace(/,/g, '')) || 100000;
                fetch('/api/execute_order', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({side: 'buy', amount: stepToman, mode: currentMode})
                }).then(r => r.json()).then(d => {
                    if(d.error) alert('❌ خطا در خرید: ' + d.error);
                    else {
                        alert('✅ سفارش خرید ارسال شد (' + (currentMode === 'live' ? 'لایو' : 'شبیه‌سازی') + ').');
                        update(); // بروزرسانی فوری
                    }
                });
            });
        }

        function manualSellAll() {
            if(!confirm("⚠️ آیا از فروش همه پله‌های " + (currentMode === 'live' ? 'لایو' : 'شبیه‌سازی') + " اطمینان دارید؟")) return;
            const amount = 0.1; // مقدار فروش
            fetch('/api/execute_order', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({side: 'sell', amount: amount, mode: currentMode})
            }).then(r => r.json()).then(d => {
                if(d.error) alert('❌ خطا در فروش: ' + d.error);
                else {
                    alert('✅ سفارش فروش ارسال شد (' + (currentMode === 'live' ? 'لایو' : 'شبیه‌سازی') + ').');
                    update(); // بروزرسانی فوری
                }
            });
        }

        function update() {
            const p = getParams();
            ['h','p','m','n','g','t','fetch'].forEach(id => {
                document.getElementById(id+'-v').innerText = p[id];
            });
            fetch('/api/process', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(p)
            })
            .then(res => {
                if(!res.ok) throw new Error('Network error');
                return res.json();
            })
            .then(data => {
                if(data.error) {
                    console.error(data.error);
                    return;
                }
                document.getElementById('bal-val').innerHTML = data.bal_html;
                document.getElementById('step-val').innerHTML = data.step_html;
                document.getElementById('live-steps').innerText = data.live_steps;
                document.getElementById('pnl-val').innerHTML = data.pnl_html;
                document.getElementById('bt-rep').innerText = data.bt_report;
                document.getElementById('bt-open-info').innerHTML = data.bt_open_info;
                document.getElementById('bt-open-list').innerHTML = data.bt_open_list;
                document.getElementById('box-history').innerHTML = data.bt_history;
                renderChart(data.chart, data.lines);
            })
            .catch(err => {
                console.error(err);
                alert('❌ خطای اتصال: ' + err.message);
            });
        }

        function renderChart(c, lines) {
            const chartLib = document.getElementById('chartLib').value;
            const chartDiv = document.getElementById('chart');

            // Preserve previous TradingView visible time range and price range (if any)
            let prevVisibleRange = null;
            let prevPriceRange = null;
            if (tvChart) {
                try { prevVisibleRange = tvChart.timeScale().getVisibleRange(); } catch (e) { prevVisibleRange = null; }
                try { prevPriceRange = tvChart.priceScale && tvChart.priceScale('right') && tvChart.priceScale('right').getPriceRange ? tvChart.priceScale('right').getPriceRange() : null; } catch (e) { prevPriceRange = null; }
            }

            chartDiv.innerHTML = ''; // Clear previous chart

            if (chartLib === 'plotly') {
                const traces = [
                    {x: c.x, y: c.y, name: 'قیمت', line: {color: '#bdc3c7', width: 2}}
                ];
                if (c.bx.length > 0) {
                    traces.push({
                        x: c.bx.map(idx => c.x[idx]), y: c.by, mode: 'markers',
                        name: 'خرید بک‌تست', marker: {color: '#3498db', size: 14, symbol: 'triangle-up'}
                    });
                }
                if (c.sx.length > 0) {
                    traces.push({
                        x: c.sx.map(idx => c.x[idx]), y: c.sy, mode: 'markers',
                        name: 'فروش بک‌تست', marker: {color: '#f39c12', size: 14, symbol: 'triangle-down'}
                    });
                }
                // SuperTrend
                let sx = [], sy = [], cd = c.dir[0];
                c.x.forEach((x, i) => {
                    if (!c.st[i] || c.st[i] === 0) return;
                    if (c.dir[i] !== cd) {
                        traces.push({
                            x: [...sx], y: [...sy],
                            mode: 'lines',
                            line: {color: cd === 1 ? '#2ecc71' : '#e74c3c', width: 3},
                            showlegend: false
                        });
                        sx = [c.x[i-1], x];
                        sy = [c.st[i-1], c.st[i]];
                        cd = c.dir[i];
                    } else {
                        sx.push(x);
                        sy.push(c.st[i]);
                    }
                });
                if (sx.length > 0) {
                    traces.push({
                        x: [...sx], y: [...sy],
                        mode: 'lines',
                        line: {color: cd === 1 ? '#2ecc71' : '#e74c3c', width: 3},
                        showlegend: false
                    });
                }
                // Lines: Avg, Target, Gap (from backend logic)
                const r = [c.x[0], c.x[c.x.length - 1]];
                if (lines.avg > 0 && showDottedLines) {
                    traces.push({
                        x: r, y: [lines.avg, lines.avg],
                        mode: 'lines',
                        line: {color: '#2ecc71', dash: 'dashdot', width: 2},
                        name: 'میانگین'
                    });
                }
                if (lines.target > 0 && showDottedLines) {
                    traces.push({
                        x: r, y: [lines.target, lines.target],
                        mode: 'lines',
                        line: {color: '#f1c40f', dash: 'dash', width: 2},
                        name: 'هدف سود'
                    });
                }
                if (lines.gap > 0 && showDottedLines) {
                    traces.push({
                        x: r, y: [lines.gap, lines.gap],
                        mode: 'lines',
                        line: {color: '#ffffff', dash: 'dot', width: 1},
                        name: 'فاصله خرید'
                    });
                }
                Plotly.newPlot('chart', traces, {
                    paper_bgcolor: 'rgba(0,0,0,0)',
                    plot_bgcolor: 'rgba(0,0,0,0)',
                    font: {color: '#ecf0f1', size: 13},
                    margin: {t: 40, b: 50, l: 60, r: 80},
                    yaxis: {side: 'right', gridcolor: '#34495e', zeroline: false},
                    xaxis: {type: 'date', showticklabels: true, gridcolor: '#34495e', tickfont: {size: 12}},
                    showlegend: true
                });
            } else if (chartLib === 'tradingview') {
                // ensure container background is dark (some browsers/styles may override)
                chartDiv.style.backgroundColor = '#000000';
                const chart = LightweightCharts.createChart(chartDiv, {
                    width: chartDiv.clientWidth,
                    height: chartDiv.clientHeight,
                    layout: {
                        background: { color: '#000000' },
                        textColor: '#ffffff',
                        fontSize: 12,
                        fontFamily: 'Vazirmatn, Tahoma, sans-serif',
                    },
                    grid: {
                        vertLines: { color: '#222831' },
                        horzLines: { color: '#222831' },
                    },
                    crosshair: {
                        mode: LightweightCharts.CrosshairMode.Normal,
                        vertLine: { width: 1, color: '#445566', style: LightweightCharts.LineStyle.Solid },
                        horzLine: { width: 1, color: '#445566', style: LightweightCharts.LineStyle.Solid },
                    },
                    rightPriceScale: {
                        borderColor: '#2b3942',
                        textColor: '#ffffff',
                        visible: true,
                    },
                    timeScale: {
                        borderColor: '#2b3942',
                        textColor: '#ffffff',
                        visible: true,
                        timeVisible: true,
                        secondsVisible: false,
                    },
                });
                const lineSeries = chart.addLineSeries({
                    color: '#bdc3c7',
                    lineWidth: 2,
                });
                const data = c.x.map((x, i) => ({time: x, value: c.y[i]}));
                lineSeries.setData(data);

                // SuperTrend
                let stData = [];
                let cd = c.dir[0];
                let stSeries = chart.addLineSeries({color: cd === 1 ? '#2ecc71' : '#e74c3c', lineWidth: 3});
                c.x.forEach((x, i) => {
                    if (!c.st[i] || c.st[i] === 0) return;
                    if (c.dir[i] !== cd) {
                        stSeries.setData(stData);
                        stSeries = chart.addLineSeries({color: c.dir[i] === 1 ? '#2ecc71' : '#e74c3c', lineWidth: 3});
                        stData = [];
                        cd = c.dir[i];
                    }
                    stData.push({time: x, value: c.st[i]});
                });
                if (stData.length > 0) stSeries.setData(stData);

                // Markers for buy/sell
                const markers = [];
                if (c.bx.length > 0) {
                    c.bx.forEach((idx, i) => {
                        markers.push({
                            time: c.x[idx],
                            position: 'belowBar',
                            color: '#3498db',
                            shape: 'arrowUp',
                            text: 'Buy',
                            size: 12
                        });
                    });
                }
                if (c.sx.length > 0) {
                    c.sx.forEach((idx, i) => {
                        markers.push({
                            time: c.x[idx],
                            position: 'aboveBar',
                            color: '#f39c12',
                            shape: 'arrowDown',
                            text: 'Sell',
                            size: 12
                        });
                    });
                }
                lineSeries.setMarkers(markers);

                // Lines for avg, target, gap
                if (lines.avg > 0 && showDottedLines) {
                    const avgLine = chart.addLineSeries({color: '#2ecc71', lineStyle: LightweightCharts.LineStyle.Dashed, lineWidth: 2});
                    avgLine.setData([{time: c.x[0], value: lines.avg}, {time: c.x[c.x.length-1], value: lines.avg}]);
                }
                if (lines.target > 0 && showDottedLines) {
                    const targetLine = chart.addLineSeries({color: '#f1c40f', lineStyle: LightweightCharts.LineStyle.Dashed, lineWidth: 2});
                    targetLine.setData([{time: c.x[0], value: lines.target}, {time: c.x[c.x.length-1], value: lines.target}]);
                }
                if (lines.gap > 0 && showDottedLines) {
                    const gapLine = chart.addLineSeries({color: '#ffffff', lineStyle: LightweightCharts.LineStyle.Dotted, lineWidth: 1});
                    gapLine.setData([{time: c.x[0], value: lines.gap}, {time: c.x[c.x.length-1], value: lines.gap}]);
                }

                // restore previous viewport (zoom/pan) if available, otherwise auto-fit
                if (prevVisibleRange) {
                    try { chart.timeScale().setVisibleRange(prevVisibleRange); } catch (e) { chart.timeScale().fitContent(); }
                } else {
                    chart.timeScale().fitContent();
                }
                // restore previous vertical price range (zoom/offset) if available
                if (prevPriceRange) {
                    try { chart.priceScale('right').setPriceRange(prevPriceRange); } catch (e) { /* ignore if API differs */ }
                }
                // keep reference for next update so we can preserve its visible & price ranges
                tvChart = chart;
            } else {
                alert('کتابخانه ' + chartLib + ' هنوز پیاده‌سازی نشده است.');
            }
        }

        function startAutoUpdate() {
            if (autoInterval) clearInterval(autoInterval);
            update();
            const fetchSec = parseInt(document.getElementById('fetch').value) || 10;
            autoInterval = setInterval(update, fetchSec * 1000);
        }

        document.getElementById('fetch').addEventListener('input', startAutoUpdate);
        document.getElementById('chartLib').addEventListener('change', update);

        window.onload = () => {
            document.getElementById('mode-indicator').className = 'mode-indicator ' + (currentMode === 'live' ? 'live-mode' : 'sim-mode');
            document.getElementById('mode-indicator').innerText = currentMode === 'live' ? '🚨 حالت لایو (ارسال واقعی سفارش)' : '🎮 حالت شبیه‌سازی (بدون ارسال سفارش)';
            startAutoUpdate();
            // Update balance every 5s
            setInterval(() => {
                const p = getParams();
                fetch('/api/process', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({...p, h:1, tf:'60'})
                }).then(r => r.json()).then(data => {
                    if(data && !data.error) {
                        document.getElementById('bal-val').innerHTML = data.bal_html;
                    }
                });
            }, 5000);

            // Add resize listener for responsive chart
            window.addEventListener('resize', update);
        };
    </script>
</body>
</html>
"""

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=4000, debug=True)