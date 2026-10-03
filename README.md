# Enterprise Chat & AI Vault

A secure, self-hosted real-time messaging application with integrated AI capabilities and military-grade data encryption. Built with PHP and a custom encrypted flat-file database system.

## 📋 Table of Contents
- [Overview](#overview)
- [Features](#features)
- [Architecture](#architecture)
- [Prerequisites](#prerequisites)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Security Considerations](#security-considerations)
- [Technical Details](#technical-details)

## 🚀 Overview
**Enterprise Chat & AI Vault** is a lightweight, high-performance chat application designed for environments where data privacy and security are critical. It eliminates the need for complex database servers by utilizing a custom-built, encrypted flat-file database engine. Additionally, it bridges the gap between human communication and artificial intelligence by allowing users to interact with multiple LLMs (Large Language Models) directly within the chat interface.

## ✨ Features

### Security & Data Integrity
- **AES-256-CBC Encryption:** All database files are encrypted at rest. The `VaultDB` class handles encryption/decryption transparently.
- **Spinlock Concurrency Control:** Prevents race conditions during simultaneous write operations using `flock` with retry logic.
- **Input Sanitization:** Strict validation of user inputs, file uploads, and AI responses to prevent XSS and injection attacks.
- **Secure File Storage:** Uploaded files are renamed with random hex strings to prevent overwriting and directory traversal.

### Messaging & UI
- **Real-Time Chat:** Polling-based real-time message updates (3-second interval).
- **Tabbed Interface:** Manage multiple conversations (user-to-user or user-to-AI) in separate tabs.
- **Smart Search:** Autocomplete functionality for finding contacts by username or full name.
- **Online Status:** Dynamic presence indicators based on `last_seen` timestamps.
- **Responsive Design:** Modern, dark-themed UI using CSS Variables and Flexbox.

### AI Integration
- **Multi-Model Support:** Toggle between different AI models (e.g., `gpt-4o`, `gemini-2.5-flash`, `claude-3-opus`) via UI toggles.
- **Contextual Responses:** The AI receives the last 20 messages of the active chat to maintain context.
- **Direct AI Chat:** A dedicated "AI Assistant" tab for general queries without needing a specific user contact.

## 🏗️ Architecture

The project follows a monolithic PHP structure with a clear separation of concerns:

1.  **`index.php`**: The main entry point. Handles routing for API endpoints (`?api=...`), serves the HTML/CSS/JS frontend, and manages session state.
2.  **`VaultDB.php`**: A custom PHP class (`App\Database\VaultDB`) that acts as the database engine. It manages:
    -   File-based storage (`.vdb` files).
    -   AES-256 encryption/decryption.
    -   CRUD operations (Create, Read, Update, Delete) with table abstraction.
    -   Concurrency locking.

**Data Flow:**
1.  Client sends JSON request via AJAX.
2.  `index.php` routes to the specific API handler (e.g., `auth`, `send_message`).
3.  Handler interacts with `VaultDB` to read/write encrypted data.
4.  If AI is involved, `index.php` makes a cURL request to the external AI API, processes the response, and saves it to the DB.
5.  JSON response is returned to the client.

## 📦 Prerequisites

-   **PHP 8.0+**: With `openssl`, `json`, and `curl` extensions enabled.
-   **Web Server**: Apache or Nginx (configured to serve PHP).
-   **Write Permissions**: The web server user must have write permissions to the `storage/` and `uploads/` directories.

## 🛠️ Installation

1.  **Clone/Download** the project files to your web server directory.
2.  **Ensure Directory Permissions**:
    ```bash
    chmod -R 777 storage/
    chmod -R 777 uploads/
    ```
    *(Note: For production, restrict permissions to the specific web server user).*

3.  **Access the Application**:
    Open your browser and navigate to `http://localhost/path/to/project/`.

## ⚙️ Configuration

Open `index.php` to configure system-wide settings:

```php
// Database Password (Used to encrypt .vdb files)
$dbPassword = 'SuperSecretEnterprisePassword99!';

// AI API Configuration
$apiKey = 'sk-your-openai-or-compatible-key-here';
$apiBaseUrl = 'https://api.gapgpt.app/v1/chat/completions'; // Change to your provider

// Storage Paths (Usually auto-detected, but can be overridden)
$storageDir = __DIR__ . '/storage';
$uploadsDir = __DIR__ . '/uploads';
```

**Important:** Change the default `$dbPassword` and `$apiKey` before deploying to production.

## 📖 Usage

### 1. Authentication
-   Upon first visit, you will see the login screen.
-   Enter a **Username** (English characters only) and **Password**.
-   Enter your **Full Name** (required for new users).
-   Click "Login/Register". The system will automatically create an account if the username doesn't exist.

### 2. Messaging
-   **Search Contacts:** Use the search bar in the sidebar to find users.
-   **Start Chat:** Click on a contact to open a new chat tab.
-   **Send Message:** Type in the input box and press Enter or click the send button.
-   **File Upload:** Click the paperclip icon to upload images or documents. Supported formats include JPG, PNG, PDF, DOCX, etc. (Max 10MB).

### 3. AI Integration
-   **Direct AI Chat:** Click the "✨ Direct Chat with AI" button in the sidebar.
-   **Select Models:** In the chat header, click the AI icons (GPT, Gemini, etc.) to activate them.
-   **Chat:** Send a message. The system will send the context to the selected models and display the responses in the chat stream.

## 🔒 Security Considerations

-   **Encryption:** Data is encrypted using AES-256-CBC. The key is derived from the `$dbPassword` using SHA-256.
-   **XSS Protection:** User-generated content is escaped using `htmlspecialchars()` before rendering.
-   **File Uploads:** Only whitelisted extensions are allowed. Files are moved to a secure directory with randomized names.
-   **Rate Limiting:** Not explicitly implemented in this version. For high-traffic production environments, consider adding rate limiting middleware.

## 🧪 Technical Details

### VaultDB Class
The `VaultDB` class uses a custom binary-like format for storage:
`Base64(IV) :: EncryptedPayload`

-   **Load:** Reads file, splits IV and payload, decrypts, and decodes JSON.
-   **Save:** Encodes JSON, generates random IV, encrypts with AES-256, and writes to file using `flock` for concurrency safety.

### API Endpoints (via `?api=`)
-   `auth`: Handles login/registration.
-   `autocomplete`: Returns matching users for search.
-   `ping`: Updates `last_seen` and returns online status of all users.
-   `get_messages`: Retrieves messages for a specific chat room.
-   `send_message`: Saves a message and triggers AI processing if applicable.
-   `upload_file`: Handles file upload, validation, and storage.

## 📂 Project Structure

```text
/
├── index.php          # Main application logic and frontend
├── VaultDB.php        # Encrypted flat-file database engine
├── storage/           # Directory for encrypted .vdb database files
│   ├── core_system.vdb
│   ├── chat_user1_user2.vdb
│   └── ...
└── uploads/           # Directory for uploaded files
    ├── <random_name>.jpg
    └── ...
```

## 🤝 Contributing
This is a specialized enterprise tool. For modifications, ensure that:
1.  Encryption keys are rotated if security is compromised.
2.  New AI models are added to the `$allowedModels` array in the frontend and backend logic.
3.  File upload extensions are updated in `index.php` if new types are required.

## 📜 License
Internal Enterprise Use. Redistribution or public deployment requires explicit permission from the project owner.
