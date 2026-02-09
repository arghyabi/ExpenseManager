import os
import grp
import pwd
import time
import socket

SCRIPT_PATH            = os.path.dirname(os.path.abspath(__file__))
WEBSITE_FOLDER         = os.path.join(SCRIPT_PATH, "web")
PHP_SERVICE_FILE       = "expense-manager-php.service"

def getUserNameAndGroup():
    # Get current username
    username = pwd.getpwuid(os.getuid()).pw_name

    # Get primary group
    groupId = os.getgid()
    primaryGroup = grp.getgrgid(groupId).gr_name

    return username, primaryGroup


def getIpAddress():
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as s:
            s.connect(("8.8.8.8", 80))
            ipAddress = s.getsockname()[0]
    except Exception:
        ipAddress = "127.0.0.1"
    return ipAddress


def addPhpServerService(dryRun = False):
    print(f"PHP Server Root Path: {WEBSITE_FOLDER}")
    userName, userGroup = getUserNameAndGroup()
    serviceFileBody = f'''
[Unit]
Description=Expense Manager PHP Server

[Service]
Type=simple
Restart=always
RestartSec=1
ExecStart=/usr/bin/php -S 0.0.0.0:8085 -t {WEBSITE_FOLDER}
User={userName}
Group={userGroup}

[Install]
WantedBy=multi-user.target

'''
    serviceFilePath = os.path.join("/etc/systemd/system", PHP_SERVICE_FILE)
    print(f"Service file: {serviceFilePath}")

    CreateService = True

    if os.path.exists(serviceFilePath):
        f = open(serviceFilePath, "r")
        data = f.read()
        f.close()

        if data.strip() == serviceFileBody.strip():
            CreateService = False

    if CreateService or dryRun:
        print(f"Creating service file: {PHP_SERVICE_FILE}")
        f = open(PHP_SERVICE_FILE, "w")
        f.write(serviceFileBody)
        f.close()

        if dryRun:
            return

        os.system(f"sudo cp {PHP_SERVICE_FILE} /etc/systemd/system")
        time.sleep(0.5)

    print("Daemon reloading...")
    os.system("sudo systemctl daemon-reload")
    time.sleep(1)

    print("Service Enabling...")
    os.system(f"sudo systemctl enable {PHP_SERVICE_FILE}")
    time.sleep(1)

    print("Service Starting...")
    os.system(f"sudo systemctl start {PHP_SERVICE_FILE}")
    time.sleep(1)

    print(f"Service Should run on http://{getIpAddress()}:8085")



if __name__ == "__main__":
    dryRun = False
    addPhpServerService(dryRun = dryRun)
