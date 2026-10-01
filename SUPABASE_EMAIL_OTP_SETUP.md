# Supabase email OTP setup

The registration screen uses Supabase Auth email confirmation and expects a six-digit code. The code is never generated or accepted in the browser; Supabase validates it through its Auth API.

## Configure the confirmation email

1. Open the BarangayConnect project in Supabase.
2. Go to **Authentication → Emails → Confirm signup**.
3. Set the subject to `BarangayConnect verification code`.
4. Use a template that includes `{{ .Token }}`, for example:

   ```html
   <h2>Verify your BarangayConnect account</h2>
   <p>Enter this six-digit code on the registration page:</p>
   <p style="font-size:28px;font-weight:bold;letter-spacing:8px">{{ .Token }}</p>
   <p>If you did not start this registration, ignore this email.</p>
   ```

5. Save the template. Keep email confirmations enabled.
6. Verify the project can send email to the address used for testing.

Supabase's default SMTP is restricted to authorized team addresses and has a low sending limit. To deliver signup codes to ordinary resident email addresses, configure a custom SMTP provider in **Authentication settings**. The provider's free allowance and sending limits depend on that provider; email delivery should not be described as unlimited or guaranteed free.

## SMS verification

The registration form supports SMS verification when phone authentication and an SMS provider are configured in the Supabase project. Supabase requires an SMS provider for phone confirmation; provider usage may incur charges. Configure phone authentication and the provider under **Authentication → Sign In / Providers → Phone**, then set `BC_SUPABASE_SMS_OTP_ENABLED=1` in the private PHP `.env` file. Leave it at `0` until delivery has been configured and tested. The SMS choice remains disabled otherwise.

The same six-digit registration panel is used for email and SMS. Resend and verification requests use the selected destination. Username sign-in also falls back to phone Auth for accounts created through SMS verification.
