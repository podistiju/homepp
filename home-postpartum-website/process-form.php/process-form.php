<?php
/**
 * Home Postpartum - Intake Form Processing
 * This script processes the intake form submission, generates a PDF, and sends it via email
 */

// ===== CONFIGURATION =====
// Email settings
define('RECIPIENT_EMAIL', 'info@homepp.ca');
define('FROM_EMAIL', 'noreply@homepp.ca');
define('FROM_NAME', 'Home Postpartum Website');
define('SUBJECT', 'New Client Intake Form Submission');

// Security settings
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB max
define('ALLOWED_DOMAINS', ['homepp.ca', 'localhost']); // Add your domain

// Set content type for JSON responses
header('Content-Type: application/json');

// ===== SECURITY FUNCTIONS =====
/**
 * Basic CSRF protection and domain validation
 */
function validateRequest() {
    // Check if request is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return false;
    }
    
    // Check referrer (basic security)
    $referrer = $_SERVER['HTTP_REFERER'] ?? '';
    $allowed = false;
    foreach (ALLOWED_DOMAINS as $domain) {
        if (strpos($referrer, $domain) !== false) {
            $allowed = true;
            break;
        }
    }
    
    return $allowed;
}

/**
 * Sanitize input data
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate email address
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number (basic)
 */
function validatePhone($phone) {
    $cleaned = preg_replace('/[^\d]/', '', $phone);
    return strlen($cleaned) >= 10;
}

// ===== MAIN PROCESSING =====
try {
    // Validate request
    if (!validateRequest()) {
        throw new Exception('Invalid request');
    }
    
    // Get and sanitize form data
    $formData = sanitizeInput($_POST);
    
    // Validate required fields
    $requiredFields = ['firstName', 'lastName', 'phn', 'dob', 'phone', 'email', 'address', 'consent'];
    $errors = [];
    
    foreach ($requiredFields as $field) {
        if (empty($formData[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
        }
    }
    
    // Validate email
    if (!empty($formData['email']) && !validateEmail($formData['email'])) {
        $errors[] = 'Invalid email address';
    }
    
    // Validate phone
    if (!empty($formData['phone']) && !validatePhone($formData['phone'])) {
        $errors[] = 'Invalid phone number';
    }
    
    // Check consent
    if (empty($formData['consent']) || $formData['consent'] !== 'on') {
        $errors[] = 'Consent is required';
    }
    
    if (!empty($errors)) {
        echo json_encode([
            'success' => false,
            'message' => 'Validation errors: ' . implode(', ', $errors)
        ]);
        exit;
    }
    
    // Generate PDF
    $pdfContent = generatePDF($formData);
    
    // Send email
    $emailSent = sendEmail($formData, $pdfContent);
    
    if ($emailSent) {
        // Log successful submission (optional)
        logSubmission($formData);
        
        echo json_encode([
            'success' => true,
            'message' => 'Form submitted successfully'
        ]);
    } else {
        throw new Exception('Failed to send email');
    }
    
} catch (Exception $e) {
    // Log error
    error_log('Form submission error: ' . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again or contact us directly.'
    ]);
}

// ===== PDF GENERATION =====
/**
 * Generate PDF content from form data
 * Note: This creates a simple text-based PDF. For professional PDFs, consider using libraries like TCPDF or FPDF
 */
function generatePDF($data) {
    $content = "CLIENT INTAKE FORM - HOME POSTPARTUM\n";
    $content .= "======================================\n\n";
    $content .= "Submission Date: " . date('Y-m-d H:i:s') . "\n\n";
    
    // Personal Information
    $content .= "PERSONAL INFORMATION\n";
    $content .= "--------------------\n";
    $content .= "Name: " . ($data['firstName'] ?? '') . " " . ($data['lastName'] ?? '') . "\n";
    $content .= "Personal Health Number: " . ($data['phn'] ?? '') . "\n";
    $content .= "Date of Birth: " . ($data['dob'] ?? '') . "\n";
    $content .= "Phone: " . ($data['phone'] ?? '') . "\n";
    $content .= "Email: " . ($data['email'] ?? '') . "\n";
    $content .= "Address: " . ($data['address'] ?? '') . "\n\n";
    
    // Pregnancy/Delivery Information
    $content .= "PREGNANCY & DELIVERY INFORMATION\n";
    $content .= "--------------------------------\n";
    $content .= "Due Date: " . ($data['dueDate'] ?? 'Not provided') . "\n";
    $content .= "Delivery Date: " . ($data['deliveryDate'] ?? 'Not provided') . "\n";
    $content .= "Delivery Information: " . ($data['deliveryInfo'] ?? 'Not provided') . "\n\n";
    
    // Medical Information
    $content .= "MEDICAL INFORMATION\n";
    $content .= "-------------------\n";
    $content .= "Pregnancy Complications: " . ($data['pregnancyComplications'] ?? 'None reported') . "\n";
    $content .= "Health Complications/Medical History: " . ($data['healthComplications'] ?? 'None reported') . "\n\n";
    
    // Care Information
    $content .= "CARE INFORMATION\n";
    $content .= "----------------\n";
    $content .= "Current Concerns: " . ($data['concerns'] ?? 'None reported') . "\n";
    $content .= "Feeding Hopes: " . ($data['feedingHopes'] ?? 'Not specified') . "\n";
    $content .= "Questions for Midwife/LC: " . ($data['questions'] ?? 'None') . "\n\n";
    
    // Emergency Contact
    $content .= "EMERGENCY CONTACT\n";
    $content .= "-----------------\n";
    $content .= "Emergency Contact: " . ($data['emergencyContact'] ?? 'Not provided') . "\n\n";
    
    $content .= "Consent Given: Yes\n";
    $content .= "Submission IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . "\n";
    
    return $content;
}

// ===== EMAIL FUNCTIONALITY =====
/**
 * Send email with PDF attachment
 */
function sendEmail($formData, $pdfContent) {
    $to = RECIPIENT_EMAIL;
    $subject = SUBJECT . ' - ' . ($formData['firstName'] ?? '') . ' ' . ($formData['lastName'] ?? '');
    
    // Create email body
    $emailBody = createEmailBody($formData);
    
    // Create PDF attachment
    $pdfFilename = 'intake_form_' . date('Y-m-d_H-i-s') . '_' . sanitizeFilename($formData['lastName'] ?? 'unknown') . '.txt';
    
    // Email headers for multipart message
    $boundary = md5(time());
    
    $headers = "From: " . FROM_NAME . " <" . FROM_EMAIL . ">\r\n";
    $headers .= "Reply-To: " . ($formData['email'] ?? FROM_EMAIL) . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
    
    // Email message
    $message = "--{$boundary}\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $message .= $emailBody . "\r\n";
    
    // Attachment
    $message .= "--{$boundary}\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8; name=\"{$pdfFilename}\"\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n";
    $message .= "Content-Disposition: attachment; filename=\"{$pdfFilename}\"\r\n\r\n";
    $message .= chunk_split(base64_encode($pdfContent)) . "\r\n";
    $message .= "--{$boundary}--\r\n";
    
    // Send email
    return mail($to, $subject, $message, $headers);
}

/**
 * Create HTML email body
 */
function createEmailBody($data) {
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .header { background: #ec4899; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; }
            .section { margin-bottom: 20px; }
            .section h3 { color: #ec4899; border-bottom: 2px solid #fce7f3; padding-bottom: 5px; }
            .field { margin-bottom: 10px; }
            .label { font-weight: bold; color: #555; }
            .value { margin-left: 10px; }
            .footer { background: #f8f9fa; padding: 15px; text-align: center; color: #666; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>New Client Intake Form</h1>
            <p>Home Postpartum Services</p>
        </div>
        
        <div class="content">
            <div class="section">
                <h3>Personal Information</h3>
                <div class="field"><span class="label">Name:</span> <span class="value">' . htmlspecialchars(($data['firstName'] ?? '') . ' ' . ($data['lastName'] ?? '')) . '</span></div>
                <div class="field"><span class="label">PHN:</span> <span class="value">' . htmlspecialchars($data['phn'] ?? '') . '</span></div>
                <div class="field"><span class="label">DOB:</span> <span class="value">' . htmlspecialchars($data['dob'] ?? '') . '</span></div>
                <div class="field"><span class="label">Phone:</span> <span class="value">' . htmlspecialchars($data['phone'] ?? '') . '</span></div>
                <div class="field"><span class="label">Email:</span> <span class="value">' . htmlspecialchars($data['email'] ?? '') . '</span></div>
                <div class="field"><span class="label">Address:</span> <span class="value">' . nl2br(htmlspecialchars($data['address'] ?? '')) . '</span></div>
            </div>
            
            <div class="section">
                <h3>Pregnancy & Delivery</h3>
                <div class="field"><span class="label">Due Date:</span> <span class="value">' . htmlspecialchars($data['dueDate'] ?? 'Not provided') . '</span></div>
                <div class="field"><span class="label">Delivery Date:</span> <span class="value">' . htmlspecialchars($data['deliveryDate'] ?? 'Not provided') . '</span></div>
                <div class="field"><span class="label">Delivery Info:</span> <span class="value">' . nl2br(htmlspecialchars($data['deliveryInfo'] ?? 'Not provided')) . '</span></div>
            </div>
            
            <div class="section">
                <h3>Medical Information</h3>
                <div class="field"><span class="label">Pregnancy Complications:</span> <span class="value">' . nl2br(htmlspecialchars($data['pregnancyComplications'] ?? 'None reported')) . '</span></div>
                <div class="field"><span class="label">Health Complications:</span> <span class="value">' . nl2br(htmlspecialchars($data['healthComplications'] ?? 'None reported')) . '</span></div>
            </div>
            
            <div class="section">
                <h3>Care Preferences</h3>
                <div class="field"><span class="label">Current Concerns:</span> <span class="value">' . nl2br(htmlspecialchars($data['concerns'] ?? 'None reported')) . '</span></div>
                <div class="field"><span class="label">Feeding Hopes:</span> <span class="value">' . nl2br(htmlspecialchars($data['feedingHopes'] ?? 'Not specified')) . '</span></div>
                <div class="field"><span class="label">Questions:</span> <span class="value">' . nl2br(htmlspecialchars($data['questions'] ?? 'None')) . '</span></div>
            </div>
            
            <div class="section">
                <h3>Emergency Contact</h3>
                <div class="field"><span class="label">Contact:</span> <span class="value">' . htmlspecialchars($data['emergencyContact'] ?? 'Not provided') . '</span></div>
            </div>
        </div>
        
        <div class="footer">
            <p>Submitted on: ' . date('F j, Y \a\t g:i A') . '</p>
            <p>Complete intake form is attached as a text file.</p>
        </div>
    </body>
    </html>';
    
    return $html;
}

// ===== UTILITY FUNCTIONS =====
/**
 * Sanitize filename
 */
function sanitizeFilename($filename) {
    return preg_replace('/[^a-zA-Z0-9_-]/', '', $filename);
}

/**
 * Log submission for record keeping
 */
function logSubmission($data) {
    $logEntry = date('Y-m-d H:i:s') . " - Form submitted by: " . 
                ($data['firstName'] ?? '') . " " . ($data['lastName'] ?? '') . 
                " (" . ($data['email'] ?? '') . ")\n";
    
    file_put_contents('submissions.log', $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Enhanced PDF generation using a simple PDF library
 * Note: This is a placeholder. For production, use a proper PDF library
 */
function generateAdvancedPDF($data) {
    // This would integrate with libraries like TCPDF, FPDF, or mPDF
    // For now, we return text content that can be saved as PDF
    return generatePDF($data);
}
?>