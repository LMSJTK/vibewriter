/**
 * VibeWriter Plot Threads JavaScript
 * Handles plot thread creation, viewing, and AI chat integration
 */

// Show new thread modal
function showNewThreadModal() {
    document.getElementById('newThreadModal').style.display = 'flex';
    setTimeout(() => {
        document.getElementById('threadTitle').focus();
    }, 100);
}

// Close new thread modal
function closeNewThreadModal() {
    document.getElementById('newThreadModal').style.display = 'none';
    document.getElementById('newThreadForm').reset();
}

// Create new thread
async function createThread(event) {
    event.preventDefault();

    const data = {
        book_id: bookId,
        title: document.getElementById('threadTitle').value,
        description: document.getElementById('threadDescription').value,
        thread_type: document.getElementById('threadType').value,
        status: document.getElementById('threadStatus').value,
        color: document.getElementById('threadColor').value
    };

    try {
        const response = await fetch('api/create_plot_thread.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (result.success) {
            // Reload page to show new thread
            window.location.reload();
        } else {
            alert('Failed to create plot thread: ' + result.message);
        }
    } catch (error) {
        console.error('Create plot thread failed:', error);
        alert('Failed to create plot thread');
    }
}

// View thread details
function viewThread(threadId) {
    window.location.href = `plot_thread_detail.php?id=${bookId}&thread=${threadId}`;
}

// Mark thread as resolved
async function markThreadResolved(threadId) {
    try {
        const response = await fetch('api/update_plot_thread.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                book_id: bookId,
                thread_id: threadId,
                status: 'resolved'
            })
        });

        const result = await response.json();

        if (result.success) {
            // Reload page to show updated status
            window.location.reload();
        } else {
            alert('Failed to mark thread as resolved: ' + result.message);
        }
    } catch (error) {
        console.error('Update thread failed:', error);
        alert('Failed to update thread status');
    }
}

// Mark thread as open
async function markThreadOpen(threadId) {
    try {
        const response = await fetch('api/update_plot_thread.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                book_id: bookId,
                thread_id: threadId,
                status: 'open'
            })
        });

        const result = await response.json();

        if (result.success) {
            // Reload page to show updated status
            window.location.reload();
        } else {
            alert('Failed to mark thread as open: ' + result.message);
        }
    } catch (error) {
        console.error('Update thread failed:', error);
        alert('Failed to update thread status');
    }
}

// Update book.js sendAIMessage to handle plot thread notifications
if (typeof window.handlePlotThreadNotifications === 'undefined') {
    window.handlePlotThreadNotifications = function(threadsCreated, threadsUpdated) {
        const hasCreated = threadsCreated && threadsCreated.length > 0;
        const hasUpdated = threadsUpdated && threadsUpdated.length > 0;

        if (hasCreated || hasUpdated) {
            showPlotThreadUpdateNotification(
                threadsCreated || [],
                threadsUpdated || []
            );
        }
    };
}

// Show notification when AI creates or updates plot threads
function showPlotThreadUpdateNotification(createdThreads, updatedThreads) {
    // Remove existing notification if any
    const existing = document.getElementById('plotThreadUpdateNotification');
    if (existing) {
        existing.remove();
    }

    // Build message based on what changed
    let messages = [];
    if (createdThreads.length > 0) {
        const threadList = createdThreads.map(thread => `${thread.title} (${thread.thread_type})`).join(', ');
        messages.push(`<strong>Created:</strong> ${threadList}`);
    }
    if (updatedThreads.length > 0) {
        const threadList = updatedThreads.map(thread => {
            const fields = thread.updated_fields.join(', ');
            return `${thread.title} (${fields})`;
        }).join(', ');
        messages.push(`<strong>Updated:</strong> ${threadList}`);
    }

    // Create notification banner
    const notification = document.createElement('div');
    notification.id = 'plotThreadUpdateNotification';
    notification.style.cssText = `
        position: fixed;
        top: 60px;
        right: 20px;
        background: #8b5cf6;
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
            <strong>🧵 Plot Threads Updated</strong><br>
            <small>${messages.join('<br>')}</small>
        </div>
        <button onclick="window.location.href='plot_threads.php?id=${bookId}'" style="
            background: white;
            color: #8b5cf6;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            margin-right: 10px;
        ">View Plot Threads</button>
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
