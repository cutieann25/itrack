<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marabut National High School - i-Tracker</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: "Book Antiqua", sans-serif;
            background: linear-gradient(135deg, #ef4444 0%, #991b1b 100%);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 0;
        }
        
        .container {
            background: white;
            padding: 40px;
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            align-items: center;
            padding-top: 30px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 50px;
            width: 100%;
        }
        
        .header-logos {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            gap: 40px;
            width: 100%;
        }
        
        .logo {
            width: 160px;
            height: 160px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f0f0f0;
            border-radius: 8px;
            flex-shrink: 0;
        }
        
        .logo img {
            max-width: 95%;
            max-height: 95%;
            object-fit: contain;
        }
        
        .header-text {
            flex: 1;
        }
        
        .gov-info {
            font-size: 13pt;
            line-height: 1.8;
            color: #333;
            margin-bottom: 12px;
            font-weight: 500;
        }
        
        .school-name {
            font-size: 18pt;
            font-weight: bold;
            color: #2c3e50;
            margin-top: 12px;
            margin-bottom: 12px;
        }
        
        .school-type {
            font-size: 12pt;
            color: #555;
            font-weight: 500;
        }
        
        .content {
            text-align: center;
            margin-top: 40px;
        }
        
        .welcome-message {
            font-size: 36px;
            color: #2c3e50;
            margin-bottom: 40px;
            font-weight: bold;
        }
        
        .button-group {
            display: flex;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 15px 40px;
            font-size: 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
            font-weight: bold;
        }
        
        .btn-primary {
            background-color: #dc2626;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #b91c1c;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(220, 38, 38, 0.4);
        }
        
        .btn-primary:active {
            transform: translateY(0);
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 20px;
                padding-top: 20px;
            }
            
            .header {
                margin-bottom: 30px;
            }
            
            .header-logos {
                flex-direction: column;
                gap: 20px;
            }
            
            .logo {
                width: 120px;
                height: 120px;
            }
            
            .gov-info {
                font-size: 11pt;
            }
            
            .school-name {
                font-size: 14pt;
            }
            
            .school-type {
                font-size: 10pt;
            }
            
            .welcome-message {
                font-size: 24px;
                margin-bottom: 25px;
            }
            
            .btn {
                padding: 12px 30px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-logos">
                <div class="logo">
                    <img src="img/marabut_logo.webp" alt="Marabut Logo">
                </div>
                <div class="header-text">
                    <div class="gov-info">
                        Republic of the Philippines<br>
                        Department of Education<br>
                        Region VIII<br>
                        Division of Samar
                    </div>
                    <div class="school-name">MARABUT NATIONAL HIGH SCHOOL</div>
                    <div class="school-type">Senior High School<br></div>
                </div>
                <div class="logo">
                    <img src="img/k-ed-logo.png" alt="DepEd Logo">
                </div>
            </div>
        </div>
        
        <div class="content">
            <div class="welcome-message">
                iTrack: A Biometric Attendance Tracking System With GPS Verification for Work Immersion in Marabut National High School
            </div>
            
            <div class="button-group">
                <a href="login.php" class="btn btn-primary">Staff sign in</a>
            </div>
        </div>
    </div>
</body>
</html>