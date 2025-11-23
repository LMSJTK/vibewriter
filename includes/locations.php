<?php
/**
 * Location Management Functions
 * VibeWriter - AI-Powered Book Writing Tool
 */

require_once __DIR__ . '/../config/config.php';

/**
 * Get all locations for a book
 */
function getLocations($bookId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT *
        FROM locations
        WHERE book_id = ?
        ORDER BY name
    ");
    $stmt->execute([$bookId]);
    return $stmt->fetchAll();
}

/**
 * Get a single location
 */
function getLocation($locationId, $bookId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT * FROM locations
        WHERE id = ? AND book_id = ?
    ");
    $stmt->execute([$locationId, $bookId]);
    return $stmt->fetch();
}

/**
 * Create a new location
 */
function createLocation($bookId, $data) {
    $pdo = getDBConnection();

    $fields = [
        'name', 'description', 'atmosphere', 'significance', 'notes', 'image'
    ];

    $values = [$bookId];
    $placeholders = ['?'];
    $setFields = ['book_id'];

    foreach ($fields as $field) {
        if (isset($data[$field])) {
            $setFields[] = $field;
            $placeholders[] = '?';
            $values[] = $data[$field];
        }
    }

    $sql = "INSERT INTO locations (" . implode(', ', $setFields) . ")
            VALUES (" . implode(', ', $placeholders) . ")";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        return ['success' => true, 'location_id' => $pdo->lastInsertId()];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Update a location
 */
function updateLocation($locationId, $bookId, $data) {
    $pdo = getDBConnection();

    $fields = [];
    $values = [];

    $allowedFields = [
        'name', 'description', 'atmosphere', 'significance', 'notes', 'image'
    ];

    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = ?";
            $values[] = $data[$field];
        }
    }

    if (empty($fields)) {
        return ['success' => false, 'message' => 'No fields to update'];
    }

    $values[] = $locationId;
    $values[] = $bookId;

    $sql = "UPDATE locations SET " . implode(', ', $fields) . " WHERE id = ? AND book_id = ?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);

        $rowCount = $stmt->rowCount();
        if ($rowCount === 0) {
            return ['success' => false, 'message' => 'Location not found or no changes made'];
        }

        return ['success' => true];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Delete a location
 */
function deleteLocation($locationId, $bookId) {
    $pdo = getDBConnection();

    try {
        $stmt = $pdo->prepare("DELETE FROM locations WHERE id = ? AND book_id = ?");
        $stmt->execute([$locationId, $bookId]);
        return ['success' => true];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Search locations by name
 */
function searchLocations($bookId, $searchTerm) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT * FROM locations
        WHERE book_id = ? AND name LIKE ?
        ORDER BY name
    ");
    $stmt->execute([$bookId, "%$searchTerm%"]);
    return $stmt->fetchAll();
}
?>
