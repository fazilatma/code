import time
import threading
import requests
from flask import Flask, render_template_string

app = Flask(__name__)

# --- تنظیمات ---
TELEGRAM_BOT_TOKEN = "1082931872:AAFdXeyMIagoS77J1Prtc-PRxCKpsYux3vM"
TELEGRAM_CHAT_ID = "-950362036"
INTERVAL = 60  # ثانیه

# --- وضعیت جریان ارسال ---
send_flag = False
send_thread = None

def send_telegram_message(message):
    """ارسال پیام تلگرام"""
    try:
        url = f"https://api.telegram.org/bot{TELEGRAM_BOT_TOKEN}/sendMessage"
        payload = {
            "chat_id": TELEGRAM_CHAT_ID,
            "text": message
        }
        response = requests.post(url, json=payload, timeout=10)
        success = response.status_code == 200
        if success:
            print(f"Telegram message sent: {message}")
        else:
            print(f"Telegram API returned status {response.status_code}")
        return success
    except Exception as e:
        print(f"Error sending telegram message: {str(e)}")
        return False

def auto_send_loop():
    """حلقه ارسال خودکار پیام"""
    global send_flag
    counter = 1
    while send_flag:
        timestamp = time.strftime('%Y-%m-%d %H:%M:%S')
        message = f"🧪 پیام تست خودکار\nشماره: {counter}\nزمان: {timestamp}"
        send_telegram_message(message)
        counter += 1
        # مکث به مدت INTERVAL ثانیه، اما هر ثانیه چک کن تا ببینیم آیا send_flag تغییر کرده
        for _ in range(INTERVAL):
            if not send_flag:
                break
            time.sleep(1)

@app.route('/')
def index():
    # وضعیت فعلی دکمه
    btn_text = "⏹️ متوقف کردن" if send_flag else "▶️ شروع ارسال"
    btn_color = "background: #e74c3c;" if send_flag else "background: #2ecc71;"
    status_text = "🟢 فعال" if send_flag else "🔴 غیرفعال"
    return render_template_string(HTML_TEMPLATE, btn_text=btn_text, btn_color=btn_color, status_text=status_text)

@app.route('/toggle')
def toggle():
    global send_flag, send_thread
    send_flag = not send_flag
    if send_flag and (send_thread is None or not send_thread.is_alive()):
        # شروع یک نخ جدید برای ارسال پیام
        send_thread = threading.Thread(target=auto_send_loop)
        send_thread.daemon = True  # این نخ با خروج اصلی متوقف شود
        send_thread.start()
    return '', 204  # بدون محتوا، فقط 204 OK

HTML_TEMPLATE = """
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ارسال تست تلگرام</title>
    <style>
        body {
            font-family: Tahoma, sans-serif;
            background-color: #2c3e50;
            color: #ecf0f1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
        }
        .container {
            background-color: #34495e;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.3);
            text-align: center;
            max-width: 400px;
            width: 90%;
        }
        h1 {
            color: #1abc9c;
            margin-bottom: 20px;
        }
        .status {
            font-size: 18px;
            margin: 20px 0;
            padding: 10px;
            border-radius: 8px;
        }
        .active {
            background-color: rgba(46, 204, 113, 0.2);
            color: #2ecc71;
        }
        .inactive {
            background-color: rgba(231, 76, 60, 0.2);
            color: #e74c3c;
        }
        button {
            padding: 15px 30px;
            font-size: 18px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            color: white;
            font-weight: bold;
            transition: background-color 0.3s;
            width: 100%;
        }
        button:hover {
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>ارسال تست تلگرام</h1>
        <div class="status {{ 'active' if send_flag else 'inactive' }}">
            وضعیت: <span id="status-text">{{ status_text }}</span>
        </div>
        <button id="toggle-btn" onclick="toggleSend()" style="{{ btn_color }}">{{ btn_text }}</button>
    </div>

    <script>
        function toggleSend() {
            const btn = document.getElementById('toggle-btn');
            const statusText = document.getElementById('status-text');

            fetch('/toggle')
            .then(response => {
                if (response.status === 204) {
                    // تغییر وضعیت دکمه و متن
                    if (btn.textContent.includes('شروع')) {
                        btn.textContent = '⏹️ متوقف کردن';
                        btn.style.backgroundColor = '#e74c3c';
                        statusText.textContent = '🟢 فعال';
                        statusText.parentElement.className = 'status active';
                    } else {
                        btn.textContent = '▶️ شروع ارسال';
                        btn.style.backgroundColor = '#2ecc71';
                        statusText.textContent = '🔴 غیرفعال';
                        statusText.parentElement.className = 'status inactive';
                    }
                } else {
                    alert('خطا در تغییر وضعیت');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('خطای شبکه');
            });
        }
    </script>
</body>
</html>
"""

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=8000, debug=True)
