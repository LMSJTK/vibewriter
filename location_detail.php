<?php
require_once 'config/config.php';
require_once 'includes/auth.php';
require_once 'includes/books.php';
require_once 'includes/locations.php';

requireLogin();

$bookId = $_GET['id'] ?? null;
$locationId = $_GET['location'] ?? null;

if (!$bookId || !$locationId) {
    redirect('dashboard.php');
}

$user = getCurrentUser();
$book = getBook($bookId, $user['id']);

if (!$book) {
    redirect('dashboard.php');
}

$location = getLocation($locationId, $bookId);

if (!$location) {
    redirect('locations.php?id=' . $bookId);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($location['name']); ?> - Locations - VibeWriter</title>
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/locations.css">
</head>
<body>
    <!-- Top Navbar -->
    <nav class="navbar">
        <div class="navbar-left">
            <a href="locations.php?id=<?php echo $bookId; ?>" class="back-link">← Back to Locations</a>
            <div class="book-title">
                <h1><?php echo h($location['name']); ?></h1>
            </div>
        </div>
        <div class="navbar-right">
            <button class="btn btn-sm btn-danger" onclick="deleteLocation()">🗑️ Delete</button>
        </div>
    </nav>

    <div class="location-detail">
        <div class="location-header">
            <div class="location-header-image">
                <?php if ($location['image']): ?>
                    <img src="<?php echo h($location['image']); ?>" alt="<?php echo h($location['name']); ?>">
                <?php else: ?>
                    <div class="location-placeholder-large">
                        <span class="location-icon-large">📍</span>
                    </div>
                <?php endif; ?>
                <div class="image-actions">
                    <button class="btn btn-sm" onclick="alert('Image generation coming soon!')">🎨 Generate Image</button>
                    <button class="btn btn-sm" onclick="alert('Image upload coming soon!')">📤 Upload Image</button>
                </div>
            </div>

            <div class="location-header-info">
                <div class="save-indicator" id="saveIndicator">
                    <span class="saved">✓ All changes saved</span>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="location-tabs">
            <button class="location-tab active" onclick="switchTab('description')">📋 Description</button>
            <button class="location-tab" onclick="switchTab('atmosphere')">🌅 Atmosphere</button>
            <button class="location-tab" onclick="switchTab('significance')">⭐ Significance</button>
            <button class="location-tab" onclick="switchTab('notes')">📝 Notes</button>
        </div>

        <!-- Tab Content -->
        <div class="tab-content active" id="tab-description">
            <div class="location-section">
                <h4>Physical Description</h4>
                <textarea id="description" rows="10" placeholder="Describe the location's physical appearance, layout, key features, architectural details..."><?php echo h($location['description']); ?></textarea>
            </div>
        </div>

        <div class="tab-content" id="tab-atmosphere">
            <div class="location-section">
                <h4>Atmosphere & Mood</h4>
                <textarea id="atmosphere" rows="10" placeholder="Describe the feeling, mood, or vibe of this place. What emotions does it evoke? What's the sensory experience? Sights, sounds, smells..."><?php echo h($location['atmosphere']); ?></textarea>
            </div>
        </div>

        <div class="tab-content" id="tab-significance">
            <div class="location-section">
                <h4>Story Significance</h4>
                <textarea id="significance" rows="10" placeholder="Why is this location important to the story? What key events happen here? How does it relate to the plot or characters?"><?php echo h($location['significance']); ?></textarea>
            </div>
        </div>

        <div class="tab-content" id="tab-notes">
            <div class="location-section">
                <h4>General Notes</h4>
                <textarea id="notes" rows="10" placeholder="Any other notes, research, historical details, or references about this location..."><?php echo h($location['notes']); ?></textarea>
            </div>
        </div>
    </div>

    <script>
        const bookId = <?php echo (int) $bookId; ?>;
        const locationId = <?php echo (int) $locationId; ?>;
        const locationName = <?php echo json_encode($location['name']); ?>;
    </script>
    <script src="assets/js/location_detail.js"></script>
</body>
</html>
