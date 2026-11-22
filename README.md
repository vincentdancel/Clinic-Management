# clinic-management

Starter foundation for a clinic management system using Laravel 13, Vue 3, Vuetify 3, and MySQL. No clinic modules are included yet.

## Project layout

```text
clinic-management/
├── backend/    Laravel API, Sanctum, and MySQL configuration
└── frontend/   Vue 3, TypeScript, Vuetify, Router, Pinia, and Axios
```

## Local setup

1. Ensure the MySQL 8.4 Windows service is running.
2. Open PowerShell in the project root and create the database. MySQL will prompt for the root password without displaying it:

```powershell
Set-Location "C:\Users\vince\Documents\Clinic-Management"
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS clinic_management_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

3. Set your local MySQL password in `backend/.env`; keep that file private.
4. From `backend/`, run `php artisan migrate`.
5. Run the backend and frontend in separate PowerShell terminals:

```powershell
# Terminal 1
Set-Location "C:\Users\vince\Documents\Clinic-Management\backend"
php artisan serve --host=localhost --port=8000

# Terminal 2
Set-Location "C:\Users\vince\Documents\Clinic-Management\frontend"
npm run dev -- --host localhost
```

The frontend API URL is set in `frontend/.env.local`. Its initial screen includes a button that checks the API health route.


cd .\backend
php artisan serve

cd frontend
npm run dev