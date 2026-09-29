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

This registration flow currently verifies the email address. SMS OTP is not enabled because the Supabase project must have phone authentication and an SMS provider configured first. SMS provider accounts may charge for messages. The form explains this so a resident is not left waiting for a text code that was never sent.
