# Network Security and MAC Address Monitor

## Overview

This project is a PHP-based network security and monitoring tool designed to automatically detect and block unknown devices on your network. It integrates with Pi-hole to manage a device blacklist and uses Telegram for real-time notifications. A web dashboard provides a comprehensive view of network activity, recent device requests, and management of blacklisted devices.

## Features

- **Automated Device Detection**: An API endpoint to log MAC addresses of devices connecting to the network.
- **Pi-hole Integration**: Automatically adds unknown devices to a Pi-hole blacklist group to restrict their network access.
- **Real-time Telegram Alerts**: Sends instant notifications for newly detected devices, blacklisting actions, and system errors.
- **Activity Dashboard**: A web interface to visualize network traffic, including hourly activity and connections per MAC address.
- **Device Management**: View a list of blacklisted devices and unblock them permanently (by adding to a known list) or temporarily directly from the dashboard.
- **Data Logging**: Persists all requests and device information in local JSON files for tracking and analysis.
- **Google Analytics**: Tracks API usage and errors for monitoring and debugging.
- **Environment-based Configuration**: Easily configure credentials and settings using a `.env` file.

## How It Works

1.  A network device (like a router or a DHCP server) sends a `POST` request with a device's MAC address to the `/api/mac_logger.php` endpoint.
2.  The API immediately logs the request and dispatches a background PHP process (`includes/process_mac.php`) to handle the logic.
3.  The background process checks if the MAC address is in the `known_devices.json` list.
4.  If the device is **unknown**:
    - The script scans the network to find the device's IP address.
    - If an IP is found, the `PiHoleClientManager` class adds the IP to a blacklist group in Pi-hole.
    - A notification is sent to a specified Telegram chat.
    - The device's details are saved in `device_database.json` and `blacklisted.json`.
5.  The `dashboard.php` page visualizes the data from these JSON files, allowing an administrator to monitor activity and manage the blacklist.

## Project Structure

```
├── api/
│   ├── mac_logger.php      # API endpoint to receive MAC addresses.
│   └── unblock_device.php  # API endpoint to handle unblocking devices.
├── includes/
│   ├── env_loader.php      # Loads environment variables from .env file.
│   ├── network_utils.php   # Utility to find a device's IP by its MAC address.
│   ├── pihole_manager.php  # Class to interact with the Pi-hole API.
│   ├── process_mac.php     # Background script to process and blacklist MACs.
│   └── telegram.php        # Function to send messages to Telegram.
├── .env.example            # Example environment configuration.
├── dashboard.php           # The main web dashboard.
├── index.php               # Main router for the application.
├── *.json                  # Data files for requests, known devices, etc.
└── README.md               # This file.
```

## Setup and Installation

### Prerequisites

- A web server with PHP installed.
- A Pi-hole instance on the same network.
- A Telegram Bot and Chat ID.
- `curl` PHP extension for API calls.

### Configuration

1.  **Clone the repository:**
    ```bash
    git clone https://github.com/mrxehmad/api.cloud.tenda.com.cn
    cd api.cloud.tenda.com.cn
    ```

2.  **Create the `.env` file:**
    Copy the `.env.example` to a new file named `.env` and fill in the required values.
    ```bash
    cp .env.example .env
    ```

    **`.env` variables:**
    - `TELEGRAM_BOT_TOKEN`: Your Telegram bot token.
    - `TELEGRAM_CHAT_ID`: The chat ID to send notifications to.
    - `PIHOLE_URL`: The full URL to your Pi-hole admin interface (e.g., `http://192.168.1.10/admin`).
    - `PIHOLE_PASSWORD`: Your Pi-hole admin password.
    - `NETWORK_INTERFACE`: The network interface to scan (e.g., `eth0`).
    - `IP_BASE`: The first three octets of your network's IP range (e.g., `192.168.1`).
    - `IP_START` / `IP_END`: The start and end of the IP range to scan.
    - `GA_MEASUREMENT_ID` / `GA_API_SECRET`: Optional Google Analytics credentials.
    - `TELEGRAM_PROXY_URL` `https://YOUR_PROXY_URL.workers.dev`

3.  **Set up data files:**
    Create the following empty JSON files in the root directory:
    - `requests.json`
    - `known_devices.json` (can be an empty array `[]`)
    - `blacklisted.json`
    - `device_database.json` (should contain `{"devices":[]}`)

4.  **Web Server Configuration:**
    Configure your web server (e.g., Nginx, Apache) to point to the project's root directory. Ensure URL rewriting is enabled so that requests are properly routed by `index.php`.

## Usage

### Logging a MAC Address

To log a device, send a `POST` request to the `/route/mac/v1` endpoint with a JSON payload containing the MAC address.

**Example using cURL:**
```bash
curl -X POST -H "Content-Type: application/json" \
-d '{"mac": "00:11:22:33:44:55"}' \
http://your-server-ip/route/mac/v1
```

This is typically automated by a service on your router that can detect new DHCP leases.

### Monitoring and Management

Access the dashboard by navigating to `http://your-server-ip/dashboard.php` in your web browser. From here, you can monitor network activity and unblock devices as needed.
