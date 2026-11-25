# AQUAR Fresh Website

A responsive web prototype based on the supplied AQUAR-Fresh Flutter MVP.

## Run locally
1. Extract this folder.
2. Open `index.html` in a modern browser.

No build tools or dependencies are required.

## Included
- Public AQUAR Fresh landing page
- Water product catalogue based on the source project (20 L refill KSh 100, 10 L refill KSh 60, 5 L bottled water KSh 180)
- Cart and checkout
- Demo M-Pesa/cash payment selection
- Customer order view
- Admin order status dashboard
- Delivery workflow
- Browser localStorage persistence

## Important limitations
This is a frontend demo. The source Flutter project describes authentication, payments, persistence, notifications and backend authorization as simulated/not production-ready. This website likewise does not process real M-Pesa payments, authenticate users securely, or store orders in a shared database. It uses browser localStorage for demo persistence. A production release needs a backend API, database, secure authentication and server-verified M-Pesa integration.
