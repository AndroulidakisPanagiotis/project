Playerbook Consent Gate (Register Redirect) — v1.4.3b
- CF7 AJAX disabled; submit sets token server-side and redirects to the official Register page.
- If user returns to Consent page with a valid token, auto-redirect to Register (prevents “same-page reload”).
- On the Register page, if token exists, email & DOB are prefilled (and confirm email too) and under-18 users are allowed to submit.
- Under-18 without token: any Register POST is hard-blocked and redirected to the consent page.
- Consent data is stored on the user record until deletion.
