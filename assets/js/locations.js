/**
 * VibeWriter Locations JavaScript
 * Handles location creation, viewing, and AI chat integration
 */

// Show new location modal
function showNewLocationModal() {
    document.getElementById('newLocationModal').style.display = 'flex';
    setTimeout(() => {
        document.getElementById('locationName').focus();
    }, 100);
}

// Close new location modal
function closeNewLocationModal() {
    document.getElementById('newLocationModal').style.display = 'none';
    document.getElementById('newLocationForm').reset();
}

// Create new location
async function createLocation(event) {
    event.preventDefault();

    const data = {
        book_id: bookId,
        name: document.getElementById('locationName').value,
        description: document.getElementById('locationDescription').value,
        atmosphere: document.getElementById('locationAtmosphere').value,
        significance: document.getElementById('locationSignificance').value,
        notes: document.getElementById('locationNotes').value
    };

    try {
        const response = await fetch('api/create_location.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (result.success) {
            // Reload page to show new location
            window.location.reload();
        } else {
            alert('Failed to create location: ' + result.message);
        }
    } catch (error) {
        console.error('Create location failed:', error);
        alert('Failed to create location');
    }
}

// View location details
function viewLocation(locationId) {
    window.location.href = `location_detail.php?id=${bookId}&location=${locationId}`;
}

// Update book.js sendAIMessage to handle location notifications
if (typeof window.handleLocationNotifications === 'undefined') {
    window.handleLocationNotifications = function(locationsCreated, locationsUpdated) {
        const hasCreated = locationsCreated && locationsCreated.length > 0;
        const hasUpdated = locationsUpdated && locationsUpdated.length > 0;

        if (hasCreated || hasUpdated) {
            showLocationUpdateNotification(
                locationsCreated || [],
                locationsUpdated || []
            );
        }
    };
}

// Show notification when AI creates or updates locations
function showLocationUpdateNotification(createdLocations, updatedLocations) {
    // Remove existing notification if any
    const existing = document.getElementById('locationUpdateNotification');
    if (existing) {
        existing.remove();
    }

    // Build message based on what changed
    let messages = [];
    if (createdLocations.length > 0) {
        const locList = createdLocations.map(loc => loc.name).join(', ');
        messages.push(`<strong>Created:</strong> ${locList}`);
    }
    if (updatedLocations.length > 0) {
        const locList = updatedLocations.map(loc => {
            const fields = loc.updated_fields.join(', ');
            return `${loc.name} (${fields})`;
        }).join(', ');
        messages.push(`<strong>Updated:</strong> ${locList}`);
    }

    // Create notification banner
    const notification = document.createElement('div');
    notification.id = 'locationUpdateNotification';
    notification.style.cssText = `
        position: fixed;
        top: 60px;
        right: 20px;
        background: #10b981;
        color: white;
        padding: 15px 20px;
        border-radius: 8px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        z-index: 10000;
        max-width: 400px;
        animation: slideIn 0.3s ease-out;
    `;

    notification.innerHTML = `
        <div style="margin-bottom: 10px;">
            <strong>🗺️ Locations Updated</strong><br>
            <small>${messages.join('<br>')}</small>
        </div>
        <button onclick="window.location.href='locations.php?id=${bookId}'" style="
            background: white;
            color: #10b981;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            margin-right: 10px;
        ">View Locations</button>
        <button onclick="this.parentElement.remove()" style="
            background: transparent;
            color: white;
            border: 1px solid white;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
        ">Dismiss</button>
    `;

    document.body.appendChild(notification);

    // Auto-dismiss after 15 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.style.animation = 'slideIn 0.3s ease-out reverse';
            setTimeout(() => notification.remove(), 300);
        }
    }, 15000);
}
