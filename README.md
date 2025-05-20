# Buy/Rent USSD - Property Management System

A USSD-based property management system that allows users to list, buy, or rent properties using their mobile phones. Built with PHP and integrated with Africa's Talking for USSD and SMS notifications.

## Developers

- URUJENI SHALLON
- GIKUNDIRO ANGE GHISLAINE

## Features

- User registration as buyer or seller with PIN setup
- Property listing management for sellers (add houses for rent or sale)
- Property browsing by location (Kigali, Huye, Musanze)
- Request management:
  - Buyers can request to rent or buy properties
  - Sellers can accept or reject rental/purchase requests
- Location-based property search with price information
- SMS notifications for:
  - Registration confirmation
  - Property request notifications
  - Request status updates
- Separate interfaces for buyers and sellers
- Navigation system with back and main menu options


## Installation

1. Open Command Prompt (CMD) and run each command separately:

   Set working directory:
   ```cmd
   cd  C:\xampp\htdocs
   ```
   

   Create project folder:
   ```cmd
   mkdir buy_rent_ussd
   ```
   

   Navigate to project folder:
   ```cmd
   cd buy_rent_ussd
   ```
   

   Clone GIKUNDIRO ANGE GHISLAINE's repository files:

   ```cmd
   git clone -b group-3-22RP03332-22RP01964-BuyOrSellUssd https://github.com/Ghislaine123/group-3-22RP03332-22RP01964-BuyOrSellUssd.git ghislaine
   ```
  

   Copy files from Ghislaine's repository:
      ```cmd
   copy ghislaine\buy_rent_ussd.sql .
   copy ghislaine\composer.json .
   copy ghislaine\composer.lock .
   copy ghislaine\sms.php .
   copy ghislaine\utils.php .
   ```

      Copy vendor directory:
   ```cmd
   xcopy ghislaine\vendor vendor /E /I /H
   ```
   

   Clone URUJENI SHALLON's repository files:
   
    ```cmd
    git clone -b group-3-22RP03332-22RP01964-BuyOrSellUssd https://github.com/shallon01/group-3-22RP03332-22RP01964-BuyOrSellUssd.git  shallon

    ```

   

   
   Copy  files from shallon's repository:
   ```cmd
   copy shallon\index.php .
   copy shallon\menu.php .
   ```
   This will include: index.php and menu.php

 
   Delete the directories ghislaine and shallon they temporaly use it finished
   ```cmd
   rmdir /S /Q ghislaine
   rmdir /S /Q shallon
   ```

3. Install PHP dependencies:
   ```bash
   composer install
   ```
   

4. Create a MySQL database named 'buy_rent_ussd':
   sql
   CREATE DATABASE buy_rent_ussd;
   

5. Import the database schema from buy_rent_ussd.sql

6. Configure your Africa's Talking credentials in sms.php:
   - Update the sandbox username and API key with your credentials
   - Update the sender ID 

## Usage

1. Start your XAMPP Apache server

2. Set up ngrok for creating a public URL
   Download and install ngrok from https://ngrok.com/download
3. Open cmd and type:
   ```cmd
   ngrok http 80
   ```
   
   This will give you a public URL like https://xyz.ngrok.io

4. Set up a USSD shortcode with Africa's Talking:
   - Log in to your Africa's Talking account
   - Create a new USSD channel
   - Set the callback URL to your ngrok URL + /buy_rent_ussd/index.php
     Example: https://xyz.ngrok.io/buy_rent_ussd/index.php

5. Users can now access the system by dialing the USSD code

6. Follow the on-screen prompts to:
   - Register as a buyer or seller
   - List properties (sellers)
   - Browse and request properties (buyers)
   - Manage property requests
   - View listings and request status
