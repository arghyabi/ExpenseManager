import os
import grp
import pwd
import time
import socket
import sys

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


def installSystemDependencies():
    """Install system-level dependencies required for the application"""
    print("\n" + "="*60)
    print("Installing system dependencies...")
    print("="*60)

    # Check if we're on Linux
    if sys.platform != "linux":
        print("Warning: This script is designed for Linux systems")
        return

    dependencies = [
        "php",
        "php-sqlite3",
        "php-curl",
        "composer",
        "git",
        "curl"
    ]

    print(f"Required packages: {', '.join(dependencies)}")

    # Try to install using apt (Debian/Ubuntu)
    try:
        print("\nAttempting to install via apt...")
        os.system(f"sudo apt-get update")
        os.system(f"sudo apt-get install -y {' '.join(dependencies)}")
        print("✓ System dependencies installed successfully")
    except Exception as e:
        print(f"⚠ Warning: Could not install via apt: {e}")
        print("Please install the following packages manually:")
        for dep in dependencies:
            print(f"  - {dep}")


def installComposerDependencies():
    """Install PHP dependencies via Composer"""
    print("\n" + "="*60)
    print("Installing PHP dependencies via Composer...")
    print("="*60)

    composer_file = os.path.join(WEBSITE_FOLDER, "composer.json")

    if not os.path.exists(composer_file):
        print(f"⚠ Warning: composer.json not found at {composer_file}")
        return

    print(f"Installing dependencies from: {composer_file}")

    try:
        # Change to web directory and run composer install
        os.chdir(WEBSITE_FOLDER)
        result = os.system("composer install --no-dev")

        if result == 0:
            print("✓ PHP dependencies installed successfully")
        else:
            print("⚠ Warning: Composer install returned a non-zero exit code")

    except Exception as e:
        print(f"⚠ Warning: Could not run composer install: {e}")
    finally:
        os.chdir(SCRIPT_PATH)


def setupDatabase():
    """Setup the SQLite database using dbTool"""
    print("\n" + "="*60)
    print("Setting up database...")
    print("="*60)

    dbtool_path = os.path.join(SCRIPT_PATH, "dbTool.py")

    if not os.path.exists(dbtool_path):
        print(f"⚠ Warning: dbTool.py not found at {dbtool_path}")
        return

    try:
        print("Initializing database...")
        os.system(f"python3 {dbtool_path} --init")
        print("✓ Database setup completed successfully")

    except Exception as e:
        print(f"⚠ Warning: Could not setup database: {e}")



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
    print("\n" + "="*60)
    print("Expense Manager Installation Script")
    print("="*60)

    dryRun = False

    # Step 1: Install system dependencies
    installSystemDependencies()

    # Step 2: Install PHP Composer dependencies
    installComposerDependencies()

    # Step 3: Setup database
    setupDatabase()

    # Step 4: Setup PHP service
    print("\n" + "="*60)
    print("Setting up PHP Service...")
    print("="*60)
    addPhpServerService(dryRun = dryRun)

    print("\n" + "="*60)
    print("Installation Complete!")
    print("="*60)
    print(f"\n✓ Expense Manager is ready at http://{getIpAddress()}:8085")
    print("="*60 + "\n")
