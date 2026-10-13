# Bushisa Figma Home preview (frontend-only)

This is a separate visual checkpoint of the Home screen. It does not connect to the database, authenticate users, send likes, or message anyone. Names and photos are fictional design examples. Some sample portraits remain placeholders because not every Figma image could be exported. Real profile photos on the PHP page remain database-driven.

## Preview safely with XAMPP on Windows

1. Download the figma-integration branch as a ZIP from GitHub (select the branch, then Code > Download ZIP).
2. Extract into a NEW folder such as C:\xampp\htdocs\Bushisa-Figma. Do not overwrite your existing local project.
3. Start Apache in XAMPP. MySQL is not required for this static preview.
4. Open http://localhost/Bushisa-Figma/preview/home.html in your browser.
5. Narrow the browser for mobile, widen it for desktop.

The real PHP page is ../discover.php and requires a configured local database and authenticated session. Do not run database/bushisa.sql against an existing database without a backup: it contains DROP TABLE commands. Two schema files in the repository differ.

## Source

- Figma Home desktop node: 4:2737
- Figma Home mobile node: 4:3145
- PHP implementation: ../discover.php
- Home-scoped styles: ../css/style.css

This is an early visual checkpoint, not pixel-perfect or production-ready. Kyle's hosted site, login, and email verification remain untouched.
