# AMIRA AL DAHAB - Apex Edition (2026)

**The definitive fusion of Security, Currency, and Social Proof.**

A premium, secure financial terminal for tier-based purchases with real cryptocurrency payment verification and gold investment portfolio management.

## Features

✅ **Advanced Security**
- Rate limiting (5 requests/minute per IP)
- Anti-timing attack randomization
- CSRF protection with nonce-based validation
- SQL injection prevention (prepared statements)
- XSS attack prevention
- Secure audit logging (hashed IPs)
- Content Security Policy headers

✅ **Crypto Payment Verification**
- Real blockchain API integration (BlockCypher, Etherscan, BscScan)
- Transaction hash validation
- Wallet address verification
- Token transfer event checking (USDT/USDC)
- Transaction age and confirmation checks

✅ **Multi-Currency Support**
- USD, NGN, EUR, AED with live conversion
- Tier-based return rates (12% - 30%)
- Gold gram calculation from investment amounts

✅ **User Management**
- Secure registration and login
- Password hashing (bcrypt)
- Session regeneration
- Guest mode (read-only)
- Purchase history tracking
- Investment portfolio management

## Requirements

- **PHP 8.0+** (with cURL and PDO SQLite support)
- **SQLite 3** (built-in with modern PHP)
- **Tailwind CSS** (CDN-loaded)
- **GSAP 3** (CDN-loaded)

## Installation

### Local Development

1. **Clone the repository:**
   ```bash
   git clone https://github.com/YOUR_USERNAME/amira-al-dahab.git
   cd amira-al-dahab
   ```

2. **Copy environment file:**
   ```bash
   cp .env.example .env
   ```

3. **Edit `.env` with your settings:**
   ```bash
   # Get API keys from:
   # - Etherscan: https://etherscan.io/apis
   # - BscScan: https://bscscan.com/apis
   # - BlockCypher: https://www.blockcypher.com/ (no key needed for BTC)
   
   PAYMENT_VERIFICATION_MODE=mock  # Use "mock" for testing, "live" for production
   ETHERSCAN_API_KEY=your_key_here
   BSCSCAN_API_KEY=your_key_here
   WALLET_BTC=your_bitcoin_address
   WALLET_ETH=your_ethereum_address
   WALLET_USDT=your_usdt_address
   WALLET_USDC=your_usdc_address
   ```

4. **Start the PHP server:**
   ```bash
   php -S localhost:8000 index.php
   ```

5. **Open in browser:**
   ```
   http://localhost:8000
   ```

## Hosting Options

### Option 1: Railway (Recommended - Easy Deploy)

1. **Create Railway account:** https://railway.app
2. **Connect GitHub:** Link your repo
3. **Add MySQL/SQLite plugin**
4. **Set environment variables** in Railway dashboard
5. **Deploy** - automatic on push

### Option 2: Heroku

1. **Install Heroku CLI**
   ```bash
   heroku login
   ```

2. **Create Procfile:**
   ```bash
   web: vendor/bin/heroku-php-apache2 public/
   ```

3. **Deploy:**
   ```bash
   heroku create your-app-name
   heroku config:set ETHERSCAN_API_KEY=your_key
   git push heroku main
   ```

### Option 3: PythonAnywhere / Render

Both support PHP hosting. Follow their PHP deployment guides.

### Option 4: Self-hosted VPS (DigitalOcean, Linode, AWS)

1. Create Ubuntu droplet
2. Install PHP, SQLite, Nginx
3. Clone repo
4. Set up SSL certificate (Let's Encrypt)
5. Configure Nginx virtual host

## File Structure

```
amira-al-dahab/
├── Amira.php                 # Main application (all-in-one)
├── index.php                 # Entry point
├── amira.db                  # SQLite database (auto-created)
├── amira-background.jpg      # Background image
├── audit.log                 # Security audit log (auto-created)
├── .env.example              # Environment variables template
├── .gitignore                # Git ignore rules
└── README.md                 # This file
```

## Configuration

### Database
- **Type:** SQLite3
- **File:** `amira.db` (auto-created on first run)
- **Tables:** 
  - `users` - User accounts
  - `purchases` - Tier purchases
  - `investments` - Gold investments

### Security Headers
All security headers are automatically set:
- X-Frame-Options: DENY
- X-Content-Type-Options: nosniff
- Content-Security-Policy: Strict
- Referrer-Policy: strict-origin-when-cross-origin

### Payment Verification

**Mock Mode** (Testing):
```php
PAYMENT_VERIFICATION_MODE=mock
```
All payments instantly verify (for testing).

**Live Mode** (Production):
- Requires valid blockchain API keys
- Verifies transactions on live blockchain
- Checks wallet addresses and transfer amounts
- Requires minimum 1 confirmation

## API Keys

### Get Free API Keys:

1. **Etherscan (ETH, USDT):**
   - Go to https://etherscan.io/apis
   - Create account → API Keys section
   - Generate new API key

2. **BscScan (USDC on BSC):**
   - Go to https://bscscan.com/apis
   - Create account → API Keys section
   - Generate new API key

3. **BlockCypher (BTC):**
   - No API key needed for free tier
   - Automatic connection to BlockCypher

## Testing

### Test Credentials
Create a test user:
- **Email:** test@example.com
- **Username:** testuser
- **Password:** TestPassword123

### Test Payments (Mock Mode)
1. Select a tier
2. Enter any transaction hash (e.g., `0x123...` for ETH)
3. Confirm payment
4. Payment verifies instantly

## Security Notes

⚠️ **Important:**

1. **Never commit `.env` file** - it contains sensitive API keys
2. **Use strong passwords** for admin accounts
3. **Enable HTTPS** in production (Let's Encrypt is free)
4. **Backup `amira.db`** regularly
5. **Review `audit.log`** for suspicious activity
6. **Update environment variables** in hosting platform
7. **Don't expose wallet private keys** - only share public addresses

## Deployment Checklist

- [ ] Set `PAYMENT_VERIFICATION_MODE=live` for production
- [ ] Add valid Etherscan API key
- [ ] Add valid BscScan API key
- [ ] Update wallet addresses with production wallets
- [ ] Enable HTTPS/SSL certificate
- [ ] Configure domain name
- [ ] Set up automated backups for `amira.db`
- [ ] Review security headers in production
- [ ] Test all payment flows
- [ ] Monitor audit logs

## Troubleshooting

### "Database error"
- Ensure PHP has write permissions to project directory
- Check that `amira.db` file can be created

### "Payment verification failed"
- Verify API keys are correct
- Check transaction hash format (must start with `0x` for ETH)
- Ensure transaction exists on blockchain
- For mock mode, set `PAYMENT_VERIFICATION_MODE=mock`

### "Rate limit exceeded"
- Wait 60 seconds before retrying
- Check if multiple users from same IP
- Adjust `RATE_LIMIT_THRESHOLD` in `.env` if needed

## Support

For issues or questions:
- Email: support@amira.dahab
- GitHub Issues: [Create issue](https://github.com/YOUR_USERNAME/amira-al-dahab/issues)

## License

Proprietary - Amira Al Dahab 2026
All rights reserved.

## Changelog

### v1.0 (May 5, 2026)
- ✅ Initial release
- ✅ Crypto payment verification (BTC, ETH, USDT, USDC)
- ✅ Advanced security features
- ✅ Multi-currency support
- ✅ Gold investment portfolio
- ✅ Tier-based purchase system
