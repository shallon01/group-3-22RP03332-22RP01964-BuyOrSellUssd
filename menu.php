<?php
require_once 'utils.php';
require_once 'sms.php';

class Database {
    private $conn;
    
    public function __construct() {
        try {
            $this->conn = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
                DB_USER,
                DB_PASS
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
    }
    
    public function getConnection() {
        return $this->conn;
    }
}

class User {
    private $db;
    private $phone;
    
    public function __construct($phone) {
        $this->db = new Database();
        $this->phone = $phone;
    }
    
    public function isRegistered() {
        $stmt = $this->db->getConnection()->prepare(
            "SELECT * FROM users WHERE phone = ?"
        );
        $stmt->execute([$this->phone]);
        return $stmt->rowCount() > 0;
    }
    
    public function register($name, $pin, $role) {
        $stmt = $this->db->getConnection()->prepare(
            "INSERT INTO users (phone, name, pin, role) VALUES (?, ?, ?, ?)"
        );
        $result = $stmt->execute([$this->phone, $name, $pin, $role]);
        
        if ($result) {
            $sms = new Sms($this->phone);
            $message = ($role === BUYER_ROLE) ? 
                sprintf(BUYER_REGISTRATION_SMS, $pin) : 
                sprintf(SELLER_REGISTRATION_SMS, $pin);
            $sms->sendSMS($message, $this->phone);
        }
        return $result;
    }
    
    public function getRole() {
        $stmt = $this->db->getConnection()->prepare(
            "SELECT role FROM users WHERE phone = ?"
        );
        $stmt->execute([$this->phone]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['role'] : null;
    }
}

class House {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    public function addListing($title, $location, $price, $type, $seller_phone) {
        $stmt = $this->db->getConnection()->prepare(
            "INSERT INTO houses (title, location, price, type, seller_phone, status) 
             VALUES (?, ?, ?, ?, ?, 'available')"
        );
        return $stmt->execute([$title, $location, $price, $type, $seller_phone]);
    }
    
    public function getHouses($location, $type) {
        $stmt = $this->db->getConnection()->prepare(
            "SELECT * FROM houses WHERE location = ? AND type = ? AND status = 'available'"
        );
        $stmt->execute([$location, $type]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getSellerHouses($seller_phone) {
        $stmt = $this->db->getConnection()->prepare(
            "SELECT * FROM houses WHERE seller_phone = ?"
        );
        $stmt->execute([$seller_phone]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLocations() {
        $stmt = $this->db->getConnection()->prepare(
            "SELECT DISTINCT location FROM houses ORDER BY location"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    public function updateStatus($house_id, $status) {
        $stmt = $this->db->getConnection()->prepare(
            "UPDATE houses SET status = ? WHERE id = ?"
        );
        return $stmt->execute([$status, $house_id]);
    }
}

class Request {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    public function createRequest($house_id, $buyer_phone) {
        $stmt = $this->db->getConnection()->prepare(
            "INSERT INTO requests (house_id, buyer_phone, status) 
             VALUES (?, ?, ?)"
        );
        $result = $stmt->execute([$house_id, $buyer_phone, PENDING_STATUS]);
        
        if ($result) {
            // Get house and seller details
            $house_stmt = $this->db->getConnection()->prepare(
                "SELECT h.*, u.phone as seller_phone, u.name as seller_name 
                 FROM houses h 
                 JOIN users u ON h.seller_phone = u.phone 
                 WHERE h.id = ?"
            );
            $house_stmt->execute([$house_id]);
            $house = $house_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Send SMS to seller
            $sms = new Sms($house['seller_phone']);
            $message = sprintf(HOUSE_REQUEST_SMS, $house['title'], $buyer_phone);
            $sms->sendSMS($message, $house['seller_phone']);
        }
        return $result;
    }
    
    public function updateRequestStatus($request_id, $status) {
        $stmt = $this->db->getConnection()->prepare(
            "UPDATE requests SET status = ? WHERE id = ?"
        );
        $result = $stmt->execute([$status, $request_id]);
        
        if ($result) {
            // Get request and house details
            $req_stmt = $this->db->getConnection()->prepare(
                "SELECT r.*, h.title, h.seller_phone 
                 FROM requests r 
                 JOIN houses h ON r.house_id = h.id 
                 WHERE r.id = ?"
            );
            $req_stmt->execute([$request_id]);
            $request = $req_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Send SMS to buyer
            $sms = new Sms($request['buyer_phone']);
            $message = ($status === ACCEPTED_STATUS) ? 
                sprintf(REQUEST_ACCEPTED_SMS, $request['title']) : 
                sprintf(REQUEST_REJECTED_SMS, $request['title']);
            $sms->sendSMS($message, $request['buyer_phone']);
        }
        return $result;
    }
    
    public function getBuyerRequests($buyer_phone) {
        $stmt = $this->db->getConnection()->prepare(
            "SELECT r.*, h.title 
             FROM requests r 
             JOIN houses h ON r.house_id = h.id 
             WHERE r.buyer_phone = ?"
        );
        $stmt->execute([$buyer_phone]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getSellerRequests($seller_phone) {
        $stmt = $this->db->getConnection()->prepare(
            "SELECT r.*, h.title, u.name as buyer_name 
             FROM requests r 
             JOIN houses h ON r.house_id = h.id 
             JOIN users u ON r.buyer_phone = u.phone 
             WHERE h.seller_phone = ?"
        );
        $stmt->execute([$seller_phone]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

class Menu {
    private $user;
    private $house;
    private $request;
    private $phone;
    
    public function __construct($phone) {
        $this->phone = $phone;
        $this->user = new User($phone);
        $this->house = new House();
        $this->request = new Request();
    }
    
    public function handleRequest($text) {
        $textArray = explode('*', $text);
        $level = count($textArray);
        
        if ($text === "") {
            if (!$this->user->isRegistered()) {
                return $this->showRegistrationMenu();
            } else {
                return $this->showMainMenu();
            }
        }
        
        // Handle go back and main menu options
        if ($text === GO_BACK) return $this->goBack($textArray);
        if ($text === GO_TO_MAIN_MENU) return $this->showMainMenu();
        
        // Handle menu navigation based on user role and session state
        $role = $this->user->getRole();
        if ($role === BUYER_ROLE) {
            return $this->handleBuyerMenu($textArray, $level);
        } elseif ($role === SELLER_ROLE) {
            return $this->handleSellerMenu($textArray, $level);
        } else {
            return $this->handleRegistration($textArray, $level);
        }
    }
    
    private function showRegistrationMenu() {
        return CONTINUE_SESSION . " Welcome to Mini House Rental!\n\n" .
               "You are not registered.\n" .
               "Please choose your role:\n\n" .
               "1. Register as Buyer\n" .
               "2. Register as Seller\n" .
               "3. Exit";
    }
    
    private function showMainMenu() {
        $role = $this->user->getRole();
        if ($role === BUYER_ROLE) {
            return CONTINUE_SESSION . " Welcome (Buyer)\n\n" .
                   "1. View Houses\n" .
                   "2. My Requests\n" .
                   "3. Exit";
        } else {
            return CONTINUE_SESSION . " Welcome (Seller)\n\n" .
                   "1. Add House Listing\n" .
                   "2. My Listings\n" .
                   "3. Buyer Requests\n" .
                   "4. Exit";
        }
    }
    
    private function handleRegistration($textArray, $level) {
        switch($level) {
            case 1:
                switch($textArray[0]) {
                    case '1':
                        return CONTINUE_SESSION . " Enter your Full Name:";
                    case '2':
                        return CONTINUE_SESSION . " Enter your Full Name:";
                    case '3':
                        return END_SESSION . " Thank you for using Mini House Rental.";
                    default:
                        return $this->showRegistrationMenu();
                }
            case 2:
                return CONTINUE_SESSION . " Set 4-digit PIN:";
            case 3:
                $name = $textArray[1];
                $pin = $textArray[2];
                $role = ($textArray[0] == '1') ? BUYER_ROLE : SELLER_ROLE;
                
                if (strlen($pin) !== 4 || !is_numeric($pin)) {
                    return CONTINUE_SESSION . " Invalid PIN. Please enter a 4-digit PIN:";
                }
                
                if ($this->user->register($name, $pin, $role)) {
                    return END_SESSION . " Registration successful as " . ucfirst($role) . "!\nThank you. Dial *123# again to continue.";
                } else {
                    return END_SESSION . " Registration failed. Please try again later.";
                }
            default:
                return $this->showRegistrationMenu();
        }
    }

    private function handleBuyerMenu($textArray, $level) {
        switch($level) {
            case 1:
                switch($textArray[0]) {
                    case '1':
                        return CONTINUE_SESSION . " Choose type:\n1. Rent\n2. Buy\n" . 
                               GO_BACK . ". Back\n" . GO_TO_MAIN_MENU . ". Main Menu";
                    case '2':
                        $requests = $this->request->getBuyerRequests($this->phone);
                        if (empty($requests)) {
                            return CONTINUE_SESSION . " No requests found.\n" . 
                                   GO_BACK . ". Back\n" . GO_TO_MAIN_MENU . ". Main Menu";
                        }
                        $menu = "Your Requests:\n";
                        foreach($requests as $index => $req) {
                            $menu .= ($index + 1) . ". {$req['title']} - {$req['status']}\n";
                        }
                        $menu .= GO_BACK . ". Back\n" . GO_TO_MAIN_MENU . ". Main Menu";
                        return CONTINUE_SESSION . $menu;
                    case '3':
                        return END_SESSION . " Thank you for using Mini House Rental.";
                    default:
                        return $this->showMainMenu();
                }
            case 2:
                if ($textArray[0] == '1') {
                    $type = ($textArray[1] == '1') ? RENT_TYPE : SALE_TYPE;
                    return CONTINUE_SESSION . " Choose location:\n1. Kigali\n2. Huye\n3. Musanze\n" .
                           GO_BACK . ". Back\n" . GO_TO_MAIN_MENU . ". Main Menu";
                }
                return $this->showMainMenu();
            case 3:
                if ($textArray[0] == '1') {
                    $type = ($textArray[1] == '1') ? RENT_TYPE : SALE_TYPE;
                    $location = '';
                    switch($textArray[2]) {
                        case '1': $location = 'Kigali'; break;
                        case '2': $location = 'Huye'; break;
                        case '3': $location = 'Musanze'; break;
                        default: return $this->showMainMenu();
                    }
                    
                    $houses = $this->house->getHouses($location, $type);
                    if (empty($houses)) {
                        return CONTINUE_SESSION . " No houses found in $location.\n" .
                               GO_BACK . ". Back\n" . GO_TO_MAIN_MENU . ". Main Menu";
                    }
                    
                    $_SESSION['houses'] = $houses; // Store houses in session for next step
                    $menu = "Choose Houses in $location:\n";
                    foreach($houses as $index => $house) {
                        $menu .= ($index + 1) . ". {$house['title']}\n";
                    }
                    $menu .= GO_BACK . ". Back\n" . GO_TO_MAIN_MENU . ". Main Menu";
                    return CONTINUE_SESSION . $menu;
                }
                return $this->showMainMenu();
            case 4:
                if ($textArray[0] == '1') {
                    $houses = $_SESSION['houses'];
                    $selected = (int)$textArray[3] - 1;
                    
                    if (isset($houses[$selected])) {
                        $_SESSION['selected_house'] = $houses[$selected];
                        return CONTINUE_SESSION . "Confirm request for {$houses[$selected]['title']}:\n" .
                               "1. Confirm\n" .
                               "2. Cancel\n" .
                               GO_BACK . ". Back\n" . 
                               GO_TO_MAIN_MENU . ". Main Menu";
                    }
                }
                return $this->showMainMenu();
            case 5:
                if ($textArray[0] == '1') {
                    $house = $_SESSION['selected_house'];
                    if ($textArray[4] == '1') { // Confirmed
                        if ($this->request->createRequest($house['id'], $this->phone)) {
                            // Send SMS to buyer confirming the request
                            $sms = new Sms($this->phone);
                            $message = "You have successfully requested to " . 
                                      ($house['type'] == RENT_TYPE ? 'rent' : 'buy') . 
                                      " {$house['title']} for {$house['price']}K RWF.";
                            $sms->sendSMS($message, $this->phone);
                            
                            return END_SESSION . "Request sent to the Seller successfully. Wait for the approval.";
                        } else {
                            return END_SESSION . "Failed to send request. Please try again later.";
                        }
                    } else if ($textArray[4] == '2') { // Cancelled
                        return END_SESSION . "Request cancelled.";
                    }
                }
                return $this->showMainMenu();
            default:
                return $this->showMainMenu();
        }
    }

    private function handleSellerMenu($textArray, $level) {
        switch($level) {
            case 1:
                switch($textArray[0]) {
                    case '1':
                        return CONTINUE_SESSION . " Enter House Title:\n" .
                               GO_BACK . ". Back\n" . GO_TO_MAIN_MENU . ". Main Menu";
                    case '2':
                        // Show seller's listings
                        $houses = $this->house->getSellerHouses($this->phone);
                        if (empty($houses)) {
                            return CONTINUE_SESSION . " No listings found.\n" .
                                   GO_BACK . ". Back\n" . GO_TO_MAIN_MENU . ". Main Menu";
                        }
                        $menu = "My Listings:\n";
                        foreach($houses as $index => $house) {
                            $menu .= ($index + 1) . ". {$house['title']} - {$house['status']}\n";
                        }
                        $menu .= GO_BACK . ". Back\n" . GO_TO_MAIN_MENU . ". Main Menu";
                        return CONTINUE_SESSION . $menu;
                    case '3':
                        $requests = $this->request->getSellerRequests($this->phone);
                        if (empty($requests)) {
                            return CONTINUE_SESSION . " No requests found.\n" .
                                   GO_BACK . ". Back\n" . GO_TO_MAIN_MENU . ". Main Menu";
                        }
                        $_SESSION['seller_requests'] = $requests; // Store requests in session
                        $menu = "Buyer Requests:\n";
                        foreach($requests as $index => $req) {
                            $menu .= ($index + 1) . ". {$req['buyer_name']} - {$req['title']}\n";
                        }
                        $menu .= GO_BACK . ". Back\n" . GO_TO_MAIN_MENU . ". Main Menu";
                        return CONTINUE_SESSION . $menu;
                    case '4':
                        return END_SESSION . " Thank you for using Mini House Rental.";
                    default:
                        return $this->showMainMenu();
                }
            case 2:
                if ($textArray[0] == '1') {
                    $locations = $this->house->getLocations();
                    $_SESSION['existing_locations'] = $locations;
                    
                    $menu = "Choose Location:\n\n";
                    foreach($locations as $index => $location) {
                        $menu .= ($index + 1) . ". $location\n";
                    }
                    $menu .= (count($locations) + 1) . ". Add New Location\n";
                    $menu .= GO_BACK . ". Back\n" . GO_TO_MAIN_MENU . ". Main Menu";
                    
                    return CONTINUE_SESSION . $menu;
                } else if ($textArray[0] == '3' && isset($_SESSION['seller_requests'])) {
                    $selected = (int)$textArray[1] - 1;
                    $requests = $_SESSION['seller_requests'];
                    
                    if (isset($requests[$selected])) {
                        $_SESSION['selected_request'] = $requests[$selected];
                        return CONTINUE_SESSION . "Confirm action for request from {$requests[$selected]['buyer_name']}:\n" .
                               "1. Approve\n" .
                               "2. Reject\n" .
                               GO_BACK . ". Back\n" . 
                               GO_TO_MAIN_MENU . ". Main Menu";
                    }
                }
                return $this->showMainMenu();
            case 3:
                if ($textArray[0] == '1') {
                    $locations = $_SESSION['existing_locations'];
                    $choice = (int)$textArray[2];
                    
                    if ($choice <= count($locations)) {
                        // Selected existing location
                        $_SESSION['new_house_location'] = $locations[$choice - 1];
                        return CONTINUE_SESSION . "Enter Price (RWF):\n" .
                               GO_BACK . ". Back\n" . GO_TO_MAIN_MENU . ". Main Menu";
                    } else if ($choice == count($locations) + 1) {
                        // Add new location
                        return CONTINUE_SESSION . "Enter house location:\n" .
                               GO_BACK . ". Back\n" . GO_TO_MAIN_MENU . ". Main Menu";
                    }
                    return $this->showMainMenu();
                } else if ($textArray[0] == '3' && isset($_SESSION['selected_request'])) {
                    $request = $_SESSION['selected_request'];
                    $choice = $textArray[2];
                    
                    if ($choice == '1' || $choice == '2') {
                        $status = ($choice == '1') ? ACCEPTED_STATUS : REJECTED_STATUS;
                        if ($this->request->updateRequestStatus($request['id'], $status)) {
                            $action = ($choice == '1') ? 'approved' : 'rejected';
                            return END_SESSION . "Request has been $action successfully.";
                        } else {
                            return END_SESSION . "Failed to update request status. Please try again.";
                        }
                    }
                }
                return $this->showMainMenu();
            case 4:
                if ($textArray[0] == '1') {
                    return CONTINUE_SESSION . " Choose Type:\n1. Rent\n2. Sale\n" .
                           GO_BACK . ". Back\n" . GO_TO_MAIN_MENU . ". Main Menu";
                }
                return $this->showMainMenu();
            case 5:
                if ($textArray[0] == '1') {
                    $title = $textArray[1];
                    
                    // Handle location based on whether it's new or existing
                    if (isset($_SESSION['new_house_location'])) {
                        $location = $_SESSION['new_house_location'];
                    } else {
                        $location = $textArray[3]; // New location entered by user
                    }
                    
                    $price = $textArray[4];
                    $type_choice = $textArray[4]; // Type choice is at index 4 in this flow
                    $type = ($type_choice == '1') ? RENT_TYPE : SALE_TYPE;
                    
                    return CONTINUE_SESSION . "Confirm house listing:\n" .
                           "Title: $title\n" .
                           "Location: $location\n" .
                           "Price: $price RWF\n" .
                           "Type: " . ucfirst($type) . "\n\n" .
                           "1. Confirm\n" .
                           "2. Cancel\n" .
                           GO_BACK . ". Back\n" . 
                           GO_TO_MAIN_MENU . ". Main Menu";
                }
                return $this->showMainMenu();
            case 6:
                if ($textArray[0] == '1') {
                    $title = $textArray[1];
                    $location = isset($_SESSION['new_house_location']) ? $_SESSION['new_house_location'] : $textArray[3];
                    $price = $textArray[4];
                    $type_choice = $textArray[4]; // Type choice is at index 4 in this flow
                    
                    if ($textArray[5] == '1') { // Confirmed
                        $type = ($type_choice == '1') ? RENT_TYPE : SALE_TYPE;
                        if ($this->house->addListing($title, $location, $price, $type, $this->phone)) {
                            // Clear session variables
                            unset($_SESSION['new_house_location']);
                            unset($_SESSION['existing_locations']);
                            return END_SESSION . "House listed successfully.";
                        } else {
                            return END_SESSION . "Failed to list house. Please try again.";
                        }
                    } else if ($textArray[5] == '2') { // Cancelled
                        unset($_SESSION['new_house_location']);
                        unset($_SESSION['existing_locations']);
                        return END_SESSION . "House listing cancelled.";
                    }
                }
                return $this->showMainMenu();
            default:
                return $this->showMainMenu();
        }
    }

    private function goBack($textArray) {
        array_pop($textArray);
        return $this->handleRequest(implode('*', $textArray));
    }
}
?>
