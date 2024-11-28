# Tenda Listener - PHP Script

This PHP script is designed to receive, display, and log HTTP requests sent to the server. It is particularly useful for debugging and inspecting the data sent by devices connecting to a Tenda router. Specifically, it can capture data sent to `api.cloud.tenda.com.cn` by redirecting it to a local server.

---

## Features

- Displays received HTTP headers.
- Captures and prints `GET` and `POST` request data.
- Shows the raw body of the request (e.g., JSON, XML, or other formats).
- Allows monitoring of data sent to `api.cloud.tenda.com.cn` when a new device connects to the router.

---

## Installation

### 1. Deploy the PHP Script

1. Place the `index.php` file in the desired directory on your web server:
   ```bash
   sudo mkdir -p /var/www/html/tenda-listener
   sudo nano /var/www/html/tenda-listener/index.php
   ```
   Add the PHP script content as described in this repository.

2. Set proper ownership and permissions for the web directory:
   ```bash
   sudo chown -R www-data:www-data /var/www/html/tenda-listener
   sudo chmod -R 755 /var/www/html/tenda-listener
   ```

3. Configure Nginx to serve the PHP script. Create a new site configuration file:
   ```bash
   sudo nano /etc/nginx/sites-available/tenda-listener
   ```
   Add the following configuration:
   ```nginx
   server {
       listen 80;
       server_name 192.168.0.x;

       root /var/www/html/tenda-listener;
       index index.php;

       location / {
           try_files $uri /index.php;
       }

       location ~ \.php$ {
           include snippets/fastcgi-php.conf;
           fastcgi_pass unix:/var/run/php/php7.4-fpm.sock; # Adjust PHP version if necessary
       }
   }
   ```

4. Enable the site:
   ```bash
   sudo ln -s /etc/nginx/sites-available/tenda-listener /etc/nginx/sites-enabled/
   ```

5. Test the configuration and reload Nginx:
   ```bash
   sudo nginx -t
   sudo systemctl reload nginx
   ```

---

### 2. Configure DNS Request Forwarding

To capture the data sent to `api.cloud.tenda.com.cn`, set up DNS request forwarding to redirect requests to your local server:

1. **Modify the DNS Configuration on the Router:**
   - Set up a DNS server (like Pi-hole or any DNS resolver) to resolve `api.cloud.tenda.com.cn` to your local server’s IP (`192.168.0.x`).
   - For example, if using Pi-hole:
     - Navigate to **Internet Settings**.
     - Add a  dns to `192.168.0.x` (your dns server).
     ![Dns Settings](/images/dns_setting.png)


2. **Verify DNS Resolution:**
   Run the following command from a device on the same network:
   ```bash
   nslookup api.cloud.tenda.com.cn
   ```
   It should return `192.168.0.x`.

3. Once configured, all traffic intended for `api.cloud.tenda.com.cn` will now be forwarded to your PHP listener.

---

## Usage

When a new device connects to the Tenda router, it sends data to `api.cloud.tenda.com.cn`. This data will be redirected to your PHP listener at `http://192.168.0.x`, where it can be inspected.

### Viewing Data

- Open `http://192.168.0.x` in a browser to view incoming requests.
- The page displays:
  1. **HTTP Headers**: Headers sent with the request.
  2. **GET/POST Data**: Any data sent as query parameters or form data.
  3. **Raw Body**: The unprocessed body of the request, such as JSON or XML payloads.

---

## Sample Data Captured

Below is an example of data captured when a new device connects to the router:

### HTTP Headers:
```
Array
(
    [Host] => api.cloud.tenda.com.cn
    [User-Agent] => TendaRouter/1.0
    [Content-Type] => application/json
    [Accept] => */*
)
```

### Raw Input Data:
```json
{
    "device_id": "123456789",
    "device_name": "Smartphone",
    "mac_address": "AA:BB:CC:DD:EE:FF",
    "connection_time": "2024-10-31T10:00:00Z"
}
```

This data shows the device ID, name, MAC address, and the connection timestamp.

---

## Notes

- This script is for **debugging purposes only** and should not be exposed publicly without additional security measures.
- Ensure the PHP version on your server supports the `getallheaders` function.
- Use HTTPS for added security if exposing this listener outside your local network.