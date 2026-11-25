
import os
import base64
from datetime import datetime

import mysql.connector
import requests
from flask import Flask, jsonify, request
from flask_cors import CORS
from flask_bcrypt import Bcrypt
from flask_jwt_extended import (
    JWTManager, create_access_token,
    jwt_required, get_jwt_identity, get_jwt
)
from dotenv import load_dotenv

load_dotenv()

app = Flask(__name__)
CORS(app)

app.config["JWT_SECRET_KEY"] = os.getenv("JWT_SECRET_KEY")
bcrypt = Bcrypt(app)
jwt = JWTManager(app)

def get_db_connection():
    return mysql.connector.connect(
        host=os.getenv("DB_HOST"),
        user=os.getenv("DB_USER"),
        password=os.getenv("DB_PASSWORD"),
        database=os.getenv("DB_NAME")
    )


def get_daraja_base_url():
    environment = os.getenv("DARAJA_ENV", "sandbox").strip().lower()
    if environment == "production":
        return "https://api.safaricom.co.ke"
    return "https://sandbox.safaricom.co.ke"


def get_daraja_access_token():
    consumer_key = os.getenv("DARAJA_CONSUMER_KEY", "").strip()
    consumer_secret = os.getenv("DARAJA_CONSUMER_SECRET", "").strip()

    if not consumer_key or not consumer_secret:
        raise ValueError("Daraja Consumer Key or Consumer Secret is missing")

    response = requests.get(
        f"{get_daraja_base_url()}/oauth/v1/generate",
        params={"grant_type": "client_credentials"},
        auth=(consumer_key, consumer_secret),
        timeout=30
    )
    response.raise_for_status()
    token = response.json().get("access_token")

    if not token:
        raise ValueError("Daraja did not return an access token")

    return token


def normalize_mpesa_phone(phone):
    phone = str(phone or "").strip().replace(" ", "").replace("-", "")

    if phone.startswith("+"):
        phone = phone[1:]
    if phone.startswith("0") and len(phone) == 10:
        phone = "254" + phone[1:]
    elif phone.startswith("7") and len(phone) == 9:
        phone = "254" + phone

    if len(phone) != 12 or not phone.startswith("254") or not phone.isdigit():
        raise ValueError("Enter a valid Kenyan M-Pesa number, e.g. 0712345678")

    return phone


@app.route("/", methods=["GET"])
def home():
    return jsonify({
        "message": "Welcome to AQUAR Fresh API",
        "status": "running"
    })

@app.route("/register", methods=["POST"])
def register():
    data = request.get_json(silent=True) or {}

    name = data.get("name", "").strip()
    email = data.get("email", "").strip().lower()
    phone = data.get("phone", "").strip()
    password = data.get("password", "")

    if not all([name, email, phone, password]):
         return jsonify({"message": "All fields are required"}), 400
    
if __name__ == "__main__":
    print("AQUAR Fresh backend is starting...")
    app.run(host="127.0.0.1", port=5000, debug=True, use_reloader=False)