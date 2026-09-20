# NexaHire Job Portal

A professional job portal with separate User and Employer journeys, OTP sign-in, account creation, job posting, applications, profiles, and MySQL persistence.

## Run with PHP and MySQL

1. Copy the folder into `htdocs` (XAMPP) or serve it from a PHP-enabled web server.
2. Import `schema.sql` into MySQL.
3. Copy `.env.example` to `.env` and set the MySQL values. If your PHP setup does not load `.env`, edit the constants at the top of `api.php`.
4. Open `http://localhost/JobPortal/`.

The frontend uses `api.php` when it is available. If opened as a plain file or without PHP, it falls back to local demo storage so every workflow can still be explored. In demo mode, the generated six-digit OTP is shown in the notification; configure an email/SMS provider before using real delivery.

## Backend endpoints

`api.php` handles `request_otp`, `verify_otp`, `register`, `login`, `jobs`, `job`, `apply`, `applications`, `profile`, and `logout`.
