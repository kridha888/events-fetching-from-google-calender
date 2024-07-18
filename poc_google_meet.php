<?php
// Include Google Client Library
require_once 'vendor/autoload.php';

// Initialize session (for storing access token)
session_start();

// Google API credentials and configuration
$clientSecretPath = 'client_secret.json'; // Path to your client secret JSON file
$redirectUri = 'http://localhost/apis/poc_google_meet.php'; // Redirect URI configured in Google Developer Console

// Path to the CA certificates file (for Guzzle HTTP client)
$caCertPath = 'C:\wamp64\bin\php\php7.4.33\extras\ssl/cacert.pem'; // Update this with your actual path

// Create Guzzle HTTP client with custom handler using the CA certificates file
$httpClient = new GuzzleHttp\Client([
    'verify' => $caCertPath, // Path to the CA certificates file
]);

// Create Google Client instance
$client = new Google\Client();
$client->setHttpClient($httpClient);
$client->setAuthConfig($clientSecretPath);
$client->addScope(Google\Service\Calendar::CALENDAR_READONLY);
$client->setRedirectUri($redirectUri);

// Handle authorization flow
if (!isset($_SESSION['access_token'])) {
    if (isset($_GET['code'])) {
        $client->fetchAccessTokenWithAuthCode($_GET['code']);
        $_SESSION['access_token'] = $client->getAccessToken();
        header('Location: ' . filter_var($redirectUri, FILTER_SANITIZE_URL));
        exit();
    } else {
        $authUrl = $client->createAuthUrl();
        echo '<a href="' . $authUrl . '">Click here to authorize access to Google Calendar</a>';
        exit();
    }
}

// Set access token from session
$client->setAccessToken($_SESSION['access_token']);

// Check if the access token is expired
if ($client->isAccessTokenExpired()) {
    // Redirect the user to re-authorize
    unset($_SESSION['access_token']);
    header('Location: ' . filter_var($redirectUri, FILTER_SANITIZE_URL));
    exit();
}

// Create Google Calendar service
$service = new Google\Service\Calendar($client);

// Database connection parameters
$dbHost = 'localhost';
$dbUsername = 'root';
$dbPassword = '';
$dbName = 'apis';

// Establish database connection
$db = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

// Function to prepare a string for SQL query
function escapeString($string) {
    global $db;
    return $db->real_escape_string($string);
}

// Function to fetch events from Google Calendar and store in database
function fetchAndStoreEvents($service, $emails, $db) {
    foreach ($emails as $email) {
        $events = fetchEventsFromCalendar($service, $email);
        if (!empty($events)) {
            foreach ($events as $event) {
                $userId = getUserIdByEmail($email, $db);
                $eventId = escapeString($event->getId());
                $summary = escapeString($event->getSummary());
                $startDateTime = escapeString($event->start->dateTime);
                $endDateTime = escapeString($event->end->dateTime);

                // Fetch attendees
                $attendees = $event->getAttendees();
                $attendeeName = '';
                $attendeeEmail = '';
                $attendeeStatus = '';

                if (!empty($attendees)) {
                    foreach ($attendees as $attendee) {
                        $attendeeName = escapeString($attendee->getDisplayName());
                        $attendeeEmail = escapeString($attendee->getEmail());
                        $attendeeStatus = escapeString($attendee->getResponseStatus());
                        // Assuming attendee link is not needed in this example
                        break; // Take only the first attendee for simplicity
                    }
                }

                // Store event details in the database
                $insertQuery = "INSERT INTO events (user_id, event_id, summary, start_datetime, end_datetime, attendee_name, attendee_email, attendee_status)
                                VALUES ('$userId', '$eventId', '$summary', '$startDateTime', '$endDateTime', '$attendeeName', '$attendeeEmail', '$attendeeStatus')";

                if ($db->query($insertQuery) === TRUE) {
                    echo "Event inserted successfully: $eventId<br>";
                } else {
                    echo "Error inserting event: " . $db->error . "<br>";
                }
            }
        } else {
            echo "No upcoming events found for the email: $email<br>";
        }
    }
}

// Function to fetch events from Google Calendar
function fetchEventsFromCalendar($service, $email) {
    // Define calendar ID (use 'primary' for primary calendar)
    $calendarId = $email;

    // Define parameters for events list request
    $optParams = array(
        'maxResults' => 10, // Maximum number of events to fetch
        'orderBy' => 'startTime',
        'singleEvents' => true,
        'timeMin' => date('c'), // Start time (now)
    );

    // Fetch events from Google Calendar
    $results = $service->events->listEvents($calendarId, $optParams);
    return $results->getItems();
}

// Function to fetch user_id based on email (you need to implement your own logic for this)
function getUserIdByEmail($email, $db) {
    // Example implementation - replace with your own logic to fetch user_id
    // For demonstration purposes, assume user_id is directly mapped to email address
    // In production, you would have a proper user management system
    $userId = null;

    // Query to fetch user_id based on email
    $query = "SELECT user_id FROM events WHERE email = '" . $db->real_escape_string($email) . "'";
    $result = $db->query($query);

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $userId = $row['user_id'];
    } else {
        // Handle case where email doesn't exist or other error
        // For simplicity, return null (you should handle this appropriately)
        $userId = null;
    }

    return $userId;
}

// Process form submission (fetch events and store in DB)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Assuming the form submits multiple emails separated by comma or newline
    $emails = explode(",", $_POST['emails']);
    // $emails = $_POST['emails'];
    fetchAndStoreEvents($service, $emails, $db);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Google Calendar Events</title>
    <style>
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #f2f2f2;
        }
    </style>
</head>
<body>
    <h1>Google Calendar Events</h1>
    <form method="POST" action="">
        <label for="emails">Enter email addresses (separated by commas):</label>
        <textarea id="emails" name="emails" rows="3" cols="50" required></textarea>
        <button type="submit">Fetch Events</button>
    </form>
    <br>

    <?php
    // Display fetched events from database
    $query = "SELECT * FROM events";
    $result = $db->query($query);

    if ($result->num_rows > 0) {
        echo "<table>";
        echo "<tr><th>Summary</th><th>Start Time</th><th>End Time</th><th>Attendee Name</th><th>Attendee Email</th><th>Attendee Status</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['summary'] . "</td>";
            echo "<td>" . $row['start_datetime'] . "</td>";
            echo "<td>" . $row['end_datetime'] . "</td>";
            echo "<td>" . $row['attendee_name'] . "</td>";
            echo "<td>" . $row['attendee_email'] . "</td>";
            echo "<td>" . $row['attendee_status'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "No events found.";
    }

    // Close database connection
    $db->close();
    ?>
</body>
</html>




