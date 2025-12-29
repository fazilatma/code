from flask import Flask

app = Flask(__name__)

@app.route("/")
def home():
    return "<h1>سلام! این پروژه روی لیارا مستقر شده است. 🎉</h1>"

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=int("8000"))
