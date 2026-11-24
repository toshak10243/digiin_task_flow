# 🚀 Digiink Task Flow

> An enterprise-grade, role-based operations management, task workflow automation, and collaboration platform designed for high-volume content, research, and technical deliverables.

---

## 📌 Overview

**Digiink Task Flow** automates the end-to-end task lifecycle across distinct operational tiers. From client brief intake to manager triage, expert execution, quality control, and final client feedback loops, the platform streamlines operations with automated Google Drive workspace provisioning, Firebase web push notifications, and detailed audit trails.

---

## 👥 Role-Based Architecture (RBAC)

The system enforces strict permission boundaries across 5 specialized roles:

| Role | Responsibilities |
|---|---|
| **Super Admin** | System oversight, user provisioning, role assignments, global analytics, and settings. |
| **BDE (Business Development Executive)** | Task creation, client brief intake, budget/word count tracking, client feedback submission with screenshots/files, and final delivery status. |
| **Operations Manager (OM)** | Workload triage, expert assignment, daily task archives, task withdrawal/reassignment, and feedback forwarding. |
| **Operations Team (Experts)** | Task execution, file/folder uploads (preserving folder directory trees), client feedback handling, task return workflows, and expert queries. |
| **Quality Control (QC)** | Work verification, automated file inspection, proof of quality, approval, or rejection with detailed rework feedback. |

---

## ✨ Key Features

### 1. 🔄 Complete End-to-End Workflow Pipeline
```
[BDE Creates Task] 
       │
       ▼
[Operations Manager Assigns Expert] ──(Auto Drive Provisioning)
       │
       ▼
[Operations Team Uploads Work] ──(Preserves Nested Folder Trees)
       │
       ▼
[QC Review Stage]
   ├── Approved  ──► [Task Marked Completed / Ready for Client]
   └── Rejected  ──► [Rework Pipeline back to Expert]
       │
       ▼
[Client Feedback Loop (BDE ──► OM ──► Team)]
```

### 2. ☁️ Google Drive Cloud Folder Automation
- Integrates with **Google Drive API v3** via OAuth2.
- Automatically provisions dedicated task folder structures:
  - `Client Files` (Original briefs, reference material, datasets).
  - `Operations Files` (Drafts, deliverable packages, version backups).
  - `Additional Files` (Auxiliary assets, client revisions).
- Uploaded files maintain complete folder hierarchies directly to Google Drive.

### 3. 🔔 Firebase Web Push Notifications (FCM HTTP v1)
- Real-time desktop push notifications across browsers (Chrome, Edge, Firefox, Brave) using Firebase Cloud Messaging HTTP v1 API.
- Instant alerts on:
  - New task assignments.
  - QC approvals and rework alerts.
  - Client feedbacks forwarded.
  - Tasks returned or withdrawn.
- Background Service Worker (`firebase-messaging-sw.js`) handles notifications even when the browser tab is inactive.

### 4. 📎 Rich Client Feedback & Screenshot Uploads
- Direct **Clipboard Paste (`Ctrl + V`)** allows BDEs to instantly paste screenshots taken with `Win+Shift+S` without saving locally.
- Multi-file attachment support for screenshots (`.png`, `.jpg`, `.webp`), PDFs, Word (`.docx`), and spreadsheets.
- Built-in **Full-screen Lightbox Zoom Modal** for fast visual inspections by Operations Managers and Experts.

### 5. ⏱️ Task Withdrawal & Auto-Expiry Governance
- **Stale Task Auto-Withdrawal**: Automatically returns tasks left unsubmitted for 30+ days back to the Operations Manager's queue.
- **Manual Task Withdrawal**: Managers can recall tasks from inactive experts.
- **Task Return Workflow**: Experts can release tasks back to the manager with a return note if requirements or deadlines cannot be met.

---

## 🛠️ Technology Stack

- **Backend**: PHP 8.1+ (Native OOP, PDO Prepared Statements, Session Security)
- **Database**: MySQL / MariaDB (InnoDB, UTF8mb4, Timezone: Asia/Kolkata +05:30)
- **Frontend**: Tailwind CSS, Vanilla JavaScript (ES6+), SweetAlert2
- **Typography & Icons**: Google Material Icons Round, Plus Jakarta Sans, Inter
- **Cloud APIs & Integrations**:
  - Google Drive REST API v3 (Google Client Library / cURL OAuth2)
  - Firebase Cloud Messaging (HTTP v1 REST with Google OAuth2 JWT)
  - PHPMailer / SMTP for transactional email notifications

---

## 🚀 Getting Started

### 1. Prerequisites
- **Web Server**: Apache / Nginx (XAMPP / WampServer / cPanel)
- **PHP**: 8.1 or higher with `pdo_mysql`, `curl`, `openssl`, and `json` extensions enabled.
- **Database**: MySQL 5.7+ or MariaDB 10.3+
- **Composer** (optional, if rebuilding vendor dependencies)

### 2. Installation

1. **Clone the repository:**
   ```bash
   git clone https://github.com/toshak10243/digiin_task_flow.git
   cd digiin_task_flow
   ```

2. **Import Database:**
   - Create a database in phpMyAdmin or MySQL CLI named `digiink_taskflow`.
   - Import the schema file located at:
     ```bash
     database/digiink_taskflow.sql
     ```

3. **Configure Environment Variables:**
   - Copy the `.env.example` file to `.env`:
     ```bash
     cp .env.example .env
     ```
   - Open `.env` and fill in your credentials:
     ```ini
     GOOGLE_CLIENT_ID=your_google_client_id.apps.googleusercontent.com
     GOOGLE_CLIENT_SECRET=your_google_client_secret
     GOOGLE_REFRESH_TOKEN=your_google_refresh_token
     GOOGLE_DRIVE_ROOT_FOLDER_ID=your_drive_root_folder_id

     SMTP_HOST=smtp.gmail.com
     SMTP_PORT=587
     SMTP_ENCRYPTION=tls
     SMTP_USERNAME=your_email@gmail.com
     SMTP_PASSWORD=your_gmail_app_password
     APP_URL=http://localhost/digiink-task-flow
     ```

4. **Configure Database Connection:**
   - Update `app/config/database.php` if your database credentials differ from local defaults:
     ```php
     private $host = "localhost";
     private $db_name = "digiink_taskflow";
     private $username = "root";
     private $password = "";
     ```

5. **Configure Firebase Push Notifications (Optional):**
   - Place your Firebase Admin Service Account JSON at:
     `config/credentials/firebase_service_account.json`
   - Update your Web App Firebase config in:
     `assets/js/fcm-client.js` and `firebase-messaging-sw.js`

6. **Directory Permissions:**
   Ensure the uploads folder is writable by the web server:
   ```bash
   chmod -R 777 uploads/
   ```

---

## 📱 Flutter Mobile Application (`digiink_app`)

Digiink Task Flow includes a native, cross-platform mobile application built in **Flutter (Dart 3.10+)** following clean architectural patterns and a dedicated **Pure Light Mode UI**.

### 🌟 Admin Module Features:
1. **Executive Dashboard**:
   - Live system metrics (Total Tasks, In Progress, Completed, Rework, Urgent, Global Word Count).
   - Date picker daily hub with breakdown of tasks and daily word throughput.
   - Leaderboards for top BDEs (sales volume) and top Operations Experts (delivered words).
   - Recent tasks live activity feed.
2. **Task Inventory & Advanced Filtering**:
   - Filter by date (`yyyy-MM-dd` or all-time) and live status chips (⚡ Urgent, In Progress, Assigned, Completed, Rework).
   - Real-time search across Task Keys, Project Names, and Client Names.
3. **Comprehensive Task Details (`AdminTaskDetailScreen`)**:
   - Complete project briefs, deadlines, word counts, and assigned experts.
   - **⚡ Highlighted Additional Information / Instructions**: Specially highlighted amber cards for supplemental client revisions so no requirements are missed.
   - One-tap Google Drive workspace links (Client files & Operations deliverables).
   - Feedback history & supplemental proof attachments viewer.
4. **User & Workforce Management**:
   - Role-filtered user tabs (Ops Team, Managers, BDE, BDM, QC).
   - Instant active/inactive user status toggle.
   - Add User bottom sheet with automatic credential provisioning.
5. **Reports & Analytics**:
   - Interactive weekly production volume bar charts (`fl_chart`).
   - Team contribution progress breakdowns.
   - Production leaders ranking and report export simulation.
6. **System Alerts & Notifications**:
   - Live categorization: All Alerts, Unread, Urgent, Feedback, and System notices.
   - Integrated FCM push diagnostic test trigger.

### 🏃 Running the Mobile App:
```bash
cd digiink_app
flutter pub get
flutter run
```

---

## 📁 Project Structure

```
digiink-task-flow/
├── api/                   # REST API suite for mobile and external integrations
│   ├── auth.php           # Token & credential authentication
│   └── admin/             # Admin endpoints (dashboard, tasks, users)
├── digiink_app/           # Flutter Mobile Application
│   ├── lib/
│   │   ├── core/          # Theme (Pure Light Mode), colors, and API endpoints
│   │   ├── data/          # Models (AdminStats, Task, User) & Services (API, Auth)
│   │   └── presentation/  # Screens (Splash, Auth, Admin) & Reusable Widgets
│   └── pubspec.yaml       # Flutter dependencies (fl_chart, google_fonts, http, intl)
├── app/
│   ├── config/            # Database and core configurations
│   ├── core/              # Sessions, helpers, and utilities
│   ├── middleware/        # Authentication & Role guards (RBAC)
│   └── services/          # Business logic (Google Drive, FCM, Notifications, Workflow)
├── assets/                # Custom stylesheets and JS scripts
├── bootstrap/             # App initialization and environment loader
├── database/              # SQL database schema and migrations
├── uploads/               # Staged client files and feedback attachments
├── views/                 # Web views (admin, bde, operations_manager, operations_team, qc)
├── firebase-messaging-sw.js # Background Web Push Service Worker
└── README.md
```

---

## 🔒 Security Best Practices

- **Zero-Secret Commits**: All private keys, service accounts, and API credentials are kept out of source control via comprehensive `.gitignore` rules.
- **SQL Injection Prevention**: 100% of database interactions utilize PDO prepared statements with bound parameters.
- **Cross-Site Scripting (XSS)**: All user-submitted feedback, notes, and strings are escaped using `htmlspecialchars()` upon rendering.
- **Role Verification**: Middleware validates active session credentials and role permissions before rendering any view or processing any POST request.

---

## 📄 License

This software is proprietary and confidential. Developed for **DigiInk Solutions**. All rights reserved.

