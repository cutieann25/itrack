<?php
date_default_timezone_set('Asia/Manila');

function location_access_allowed() {
    $current_time = date('H:i');
    return $current_time >= '06:00' && $current_time < '18:00';
}

if (!location_access_allowed()) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Location Map - i-Tracker</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: linear-gradient(180deg, #f8fafc 0%, #e2e8f0 100%);
            height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .map-header {
            background: #ffffff;
            padding: 20px 30px;
            box-shadow: 0 2px 10px rgba(15, 23, 42, 0.08);
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .map-title {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .map-title h1 {
            font-size: 1.8rem;
            color: #102a43;
            margin: 0;
        }

        .map-title p {
            font-size: 0.95rem;
            color: #64748b;
            margin: 0;
        }

        .map-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .live-status {
            font-size: 0.85rem;
            font-weight: 600;
            padding: 6px 14px;
            border-radius: 20px;
            background: #e2e8f0;
            color: #475569;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .live-status.active {
            background: #dcfce7;
            color: #15803d;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 24px;
            border-radius: 999px;
            border: none;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 600;
            text-decoration: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }

        .btn-back {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #cbd5e1;
        }

        .btn-back:hover {
            background: #e2e8f0;
            transform: translateY(-1px);
        }

        .map-container {
            flex: 1;
            overflow: hidden;
            padding: 20px;
            position: relative;
        }

        #map {
            width: 100%;
            height: 100%;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(15, 23, 42, 0.1);
        }

        .info-panel {
            position: absolute;
            bottom: 30px;
            left: 30px;
            display: none;
            background: #ffffff;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.15);
            z-index: 999;
            width: min(360px, calc(100vw - 60px));
        }

        .info-panel h3 {
            margin: 0 0 18px 0;
            color: #102a43;
            font-size: 1rem;
        }

        .info-item {
            display: flex;
            align-items: flex-start;
            gap: 18px;
            margin-bottom: 14px;
            font-size: 0.9rem;
            color: #475569;
        }

        .info-item:last-child {
            margin-bottom: 0;
        }

        .info-item strong {
            flex: 0 0 135px;
            color: #102a43;
            line-height: 1.4;
        }

        .info-item span {
            min-width: 0;
            line-height: 1.4;
            overflow-wrap: anywhere;
            text-align: right;
        }

        @media (max-width: 900px) {
            .map-header {
                flex-direction: column;
                align-items: flex-start;
                padding: 16px 20px;
            }

            .map-title h1 {
                font-size: 1.5rem;
            }

            .map-actions {
                width: 100%;
                justify-content: space-between;
            }

            .info-panel {
                bottom: 20px;
                left: 20px;
                width: min(320px, calc(100vw - 40px));
                padding: 20px;
            }
        }

        @media (max-width: 680px) {
            .map-container {
                padding: 10px;
            }

            #map {
                border-radius: 12px;
            }

            .info-panel {
                bottom: 15px;
                left: 15px;
                width: calc(100vw - 30px);
                padding: 18px;
            }

            .info-item {
                gap: 12px;
            }

            .info-item strong {
                flex-basis: 118px;
            }
        }
    </style>
</head>
<body>
    <div class="map-header">
        <div class="map-title">
            <h1>Location Map</h1>
            <p>Student GPS Attendance Verification</p>
        </div>
        <div class="map-actions">
            <span class="live-status" id="liveStatus">Connecting Firebase...</span>
            <a href="dashboard.php" class="btn btn-back">← Back to Dashboard</a>
        </div>
    </div>

    <div class="map-container">
        <div id="map"></div>
        <div class="info-panel" id="infoPanel">
            <h3>Location Details</h3>
            <div class="info-item">
                <strong>Student Full Name:</strong>
                <span id="studentId">-</span>
            </div>
            <div class="info-item">
                <strong>Strand:</strong>
                <span id="strandName">-</span>
            </div>
            <div class="info-item">
                <strong>Attendance Date:</strong>
                <span id="attendanceDate">-</span>
            </div>
            <div class="info-item">
                <strong>Attendance Time:</strong>
                <span id="attendanceTime">-</span>
            </div>
            <div class="info-item">
                <strong>Attendance Type:</strong>
                <span id="attendanceType">-</span>
            </div>
            <div class="info-item">
                <strong>Assigned Agency:</strong>
                <span id="agencyName">-</span>
            </div>
            <div class="info-item">
                <strong>Latitude:</strong>
                <span id="latitude">-</span>
            </div>
            <div class="info-item">
                <strong>Longitude:</strong>
                <span id="longitude">-</span>
            </div>
        </div>
    </div>

    <!-- Firebase JS SDKs -->
    <script src="https://www.gstatic.com/firebasejs/9.22.0/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/9.22.0/firebase-database-compat.js"></script>

    <script>
        function escapeHtml(value) {
            return String(value).replace(/[&<>'"]/g, function (character) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    "'": '&#39;',
                    '"': '&quot;'
                }[character];
            });
        }

        // Firebase Configuration
        const firebaseConfig = {
            apiKey: "AIzaSyDGKFw1c5_88GJozPr3TNjuk10Vw4rDYsE",
            authDomain: "i-tracker-ee8f6.firebaseapp.com",
            databaseURL: "https://i-tracker-ee8f6-default-rtdb.asia-southeast1.firebasedatabase.app",
            projectId: "i-tracker-ee8f6",
            storageBucket: "i-tracker-ee8f6.firebasestorage.app",
            messagingSenderId: "89083225675",
            appId: "1:89083225675:android:c92d5c2a0ef7692ff84fce"
        };

        let isFirebaseInitialized = false;
        try {
            firebase.initializeApp(firebaseConfig);
            isFirebaseInitialized = true;
        } catch (e) {
            console.warn("Firebase initialization error:", e);
        }

        const params = new URLSearchParams(window.location.search);
        const searchStudentName = (params.get('student_id') || params.get('student_name') || params.get('name') || '').trim();
        const selectedLatitude = Number(params.get('lat'));
        const selectedLongitude = Number(params.get('lng'));
        const hasSelectedLocation = params.has('lat')
            && params.has('lng')
            && Number.isFinite(selectedLatitude)
            && Number.isFinite(selectedLongitude)
            && selectedLatitude >= -90
            && selectedLatitude <= 90
            && selectedLongitude >= -180
            && selectedLongitude <= 180;
        let requestedLocations = null;
        if (params.has('locations')) {
            try {
                const parsedLocations = JSON.parse(params.get('locations'));
                if (Array.isArray(parsedLocations)) {
                    requestedLocations = parsedLocations.filter(function (location) {
                        const lat = Number(location.lat);
                        const lng = Number(location.lng);
                        return Number.isFinite(lat)
                            && Number.isFinite(lng)
                            && location.lat !== null
                            && location.lng !== null
                            && String(location.lat).trim() !== ''
                            && String(location.lng).trim() !== ''
                            && lat >= -90
                            && lat <= 90
                            && lng >= -180
                            && lng <= 180;
                    });
                }
            } catch (error) {
                console.error('Unable to read the selected student attendance locations:', error);
            }
        }

        let map;
        let infoWindow;
        let activeMarkers = {};

        function updateDetailsPanel(name, strand, date, time, type, agency, lat, lng) {
            document.getElementById('infoPanel').style.display = 'block';
            document.getElementById('studentId').textContent = name || searchStudentName || '-';
            document.getElementById('strandName').textContent = strand || '-';
            document.getElementById('attendanceDate').textContent = date || '-';
            document.getElementById('attendanceTime').textContent = time || '-';
            document.getElementById('attendanceType').textContent = type || '-';
            document.getElementById('agencyName').textContent = agency || '-';
            document.getElementById('latitude').textContent = typeof lat === 'number' ? lat.toFixed(8) : lat;
            document.getElementById('longitude').textContent = typeof lng === 'number' ? lng.toFixed(8) : lng;
        }

        function initMap() {
            const defaultPos = { lat: 11.2961, lng: 125.5896 };

            map = new google.maps.Map(document.getElementById('map'), {
                center: defaultPos,
                zoom: 20,
                maxZoom: 21,
                mapTypeId: 'hybrid',
                streetViewControl: false,
                fullscreenControl: true
            });

            infoWindow = new google.maps.InfoWindow();

            if (params.has('locations')) {
                const liveStatusEl = document.getElementById('liveStatus');
                if (!requestedLocations || requestedLocations.length === 0) {
                    liveStatusEl.textContent = 'No attendance locations found';
                    return;
                }

                liveStatusEl.textContent = 'Showing This Student’s Attendance Locations';
                liveStatusEl.classList.add('active');
                const bounds = new google.maps.LatLngBounds();
                const studentName = searchStudentName || '-';

                requestedLocations.forEach(function (location, index) {
                    const lat = Number(location.lat);
                    const lng = Number(location.lng);
                    const position = { lat: lat, lng: lng };
                    const date = location.date || '-';
                    const time = location.time || '-';
                    const attendanceType = location.attendance_type || '-';
                    const strand = location.strand || '-';
                    const agency = location.agency || '-';
                    const marker = new google.maps.Marker({
                        position: position,
                        map: map,
                        title: studentName + ' - ' + attendanceType,
                        label: String(index + 1)
                    });
                    const popupContent = `
                        <div style="color: #102a43; font-family: sans-serif; padding: 4px; max-width: 240px;">
                            <h4 style="margin: 0 0 6px 0; font-size: 0.95rem; color: #0284c7;">${escapeHtml(studentName)}</h4>
                            <p style="margin: 0 0 4px 0; font-size: 0.85rem;"><strong>Attendance Type:</strong> ${escapeHtml(attendanceType)}</p>
                            <p style="margin: 0 0 4px 0; font-size: 0.85rem;"><strong>Date:</strong> ${escapeHtml(date)}</p>
                            <p style="margin: 0 0 4px 0; font-size: 0.85rem;"><strong>Time:</strong> ${escapeHtml(time)}</p>
                            <p style="margin: 0 0 4px 0; font-size: 0.85rem;"><strong>Strand:</strong> ${escapeHtml(strand)}</p>
                            <p style="margin: 0 0 4px 0; font-size: 0.85rem;"><strong>Agency:</strong> ${escapeHtml(agency)}</p>
                            <p style="margin: 0; font-size: 0.8rem; color: #64748b;"><strong>Coords:</strong><br>${lat.toFixed(8)}, ${lng.toFixed(8)}</p>
                        </div>
                    `;

                    marker.addListener('click', function () {
                        infoWindow.setContent(popupContent);
                        infoWindow.open(map, marker);
                        updateDetailsPanel(studentName, strand, date, time, attendanceType, agency, lat, lng);
                    });
                    bounds.extend(position);

                    if (index === 0) {
                        updateDetailsPanel(studentName, strand, date, time, attendanceType, agency, lat, lng);
                    }
                });

                if (requestedLocations.length === 1) {
                    map.setCenter(bounds.getCenter());
                    map.setZoom(20);
                } else {
                    map.fitBounds(bounds);
                }
                return;
            }

            if (hasSelectedLocation) {
                const liveStatusEl = document.getElementById('liveStatus');
                liveStatusEl.textContent = 'Selected Attendance Location';
                liveStatusEl.classList.add('active');

                const selectedPosition = { lat: selectedLatitude, lng: selectedLongitude };
                const selectedDate = params.get('date') || '-';
                const selectedTime = params.get('time') || '-';
                const selectedType = params.get('attendance_type') || '-';
                const selectedStrand = params.get('strand') || '-';
                const selectedAgency = params.get('agency') || '-';
                const selectedName = searchStudentName || '-';
                const selectedMarker = new google.maps.Marker({
                    position: selectedPosition,
                    map: map,
                    title: selectedName + ' - ' + selectedType,
                    animation: google.maps.Animation.DROP
                });
                const selectedPopupContent = `
                    <div style="color: #102a43; font-family: sans-serif; padding: 4px; max-width: 220px;">
                        <h4 style="margin: 0 0 6px 0; font-size: 0.95rem; color: #0284c7;">${escapeHtml(selectedName)}</h4>
                        <p style="margin: 0 0 4px 0; font-size: 0.85rem;"><strong>Attendance Type:</strong> ${escapeHtml(selectedType)}</p>
                        <p style="margin: 0 0 4px 0; font-size: 0.85rem;"><strong>Date:</strong> ${escapeHtml(selectedDate)}</p>
                        <p style="margin: 0 0 4px 0; font-size: 0.85rem;"><strong>Time:</strong> ${escapeHtml(selectedTime)}</p>
                        <p style="margin: 0 0 4px 0; font-size: 0.85rem;"><strong>Strand:</strong> ${escapeHtml(selectedStrand)}</p>
                        <p style="margin: 0 0 4px 0; font-size: 0.85rem;"><strong>Agency:</strong> ${escapeHtml(selectedAgency)}</p>
                        <p style="margin: 0; font-size: 0.8rem; color: #64748b;"><strong>Coords:</strong><br>${selectedLatitude.toFixed(8)}, ${selectedLongitude.toFixed(8)}</p>
                    </div>
                `;

                map.setCenter(selectedPosition);
                map.setZoom(20);
                infoWindow.setContent(selectedPopupContent);
                infoWindow.open(map, selectedMarker);
                selectedMarker.addListener('click', function () {
                    infoWindow.setContent(selectedPopupContent);
                    infoWindow.open(map, selectedMarker);
                });
                updateDetailsPanel(selectedName, selectedStrand, selectedDate, selectedTime, selectedType, selectedAgency, selectedLatitude, selectedLongitude);
                return;
            }

            if (isFirebaseInitialized) {
                const liveStatusEl = document.getElementById('liveStatus');
                const studentLocationsRef = firebase.database().ref('student_locations');

                liveStatusEl.textContent = "🟢 Live Realtime Connected";
                liveStatusEl.classList.add('active');

                studentLocationsRef.on('value', (snapshot) => {
                    const data = snapshot.val();
                    if (!data) return;

                    const cleanSearchKey = searchStudentName.toLowerCase().replace(/[^a-zA-Z0-9]/g, '');

                    Object.keys(data).forEach((key) => {
                        const log = data[key];
                        if (!log.latitude || !log.longitude) return;

                        const name = (log.student_name || key || '').trim();
                        const cleanName = name.toLowerCase().replace(/[^a-zA-Z0-9]/g, '');

                        if (cleanSearchKey && !isNaN(cleanSearchKey) === false && !cleanName.includes(cleanSearchKey) && !cleanSearchKey.includes(cleanName)) {
                            return;
                        }

                        const lat = parseFloat(log.latitude);
                        const lng = parseFloat(log.longitude);
                        const strand = log.strand || '-';
                        const action = log.action || 'IN';
                        const period = log.period || '';
                        const dateStr = log.timestamp ? new Date(log.timestamp).toLocaleDateString() : new Date().toLocaleDateString();
                        const timeStr = log.timestamp ? new Date(log.timestamp).toLocaleTimeString() : 'Just now';

                        const pos = { lat: lat, lng: lng };

                        const precisionTargetIcon = {
                            path: google.maps.SymbolPath.CIRCLE,
                            scale: 8,
                            fillColor: action === 'IN' ? '#0284c7' : '#ef4444',
                            fillOpacity: 1,
                            strokeColor: '#ffffff',
                            strokeWeight: 2.5
                        };

                        if (activeMarkers[key]) {
                            activeMarkers[key].setPosition(pos);
                            activeMarkers[key].setIcon(precisionTargetIcon);
                        } else {
                            activeMarkers[key] = new google.maps.Marker({
                                position: pos,
                                map: map,
                                icon: precisionTargetIcon,
                                title: name + ' - Time ' + action,
                                animation: google.maps.Animation.DROP
                            });
                        }

                        map.panTo(pos);
                        map.setZoom(20);

                        const popupContent = `
                            <div style="color: #102a43; font-family: sans-serif; padding: 4px; max-width: 220px;">
                                <h4 style="margin: 0 0 6px 0; font-size: 0.95rem; color: #0284c7;">📍 ${escapeHtml(name)}</h4>
                                <p style="margin: 0 0 4px 0; font-size: 0.85rem;"><strong>Status:</strong> ${escapeHtml(action)} (${escapeHtml(period)})</p>
                                <p style="margin: 0 0 4px 0; font-size: 0.85rem;"><strong>Time:</strong> ${escapeHtml(timeStr)}</p>
                                <p style="margin: 0; font-size: 0.8rem; color: #64748b;"><strong>Coords:</strong><br>${lat.toFixed(8)}, ${lng.toFixed(8)}</p>
                            </div>
                        `;

                        activeMarkers[key].addListener('click', function () {
                            infoWindow.setContent(popupContent);
                            infoWindow.open(map, activeMarkers[key]);
                            updateDetailsPanel(name, strand, dateStr, timeStr, action + " (" + period + ")", "-", lat, lng);
                        });

                        infoWindow.setContent(popupContent);
                        infoWindow.open(map, activeMarkers[key]);
                        updateDetailsPanel(name, strand, dateStr, timeStr, action + " (" + period + ")", "-", lat, lng);
                    });
                });
            } else {
                document.getElementById('liveStatus').textContent = "Offline Mode";
            }
        }
    </script>
    <script async defer src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDZGxT5GY8G4LpCNVzsuhztkxPM2TBwRPo&loading=async&callback=initMap"></script>
</body>
</html>
