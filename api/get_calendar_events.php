<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin'); // adjust if doctor/saleslady needs access too, for now limit to logged in staff
header('Content-Type: application/json');

try {
    $db = getDB();
    
    $start = $_GET['start'] ?? null;
    $end = $_GET['end'] ?? null;

    $query = "
        SELECT 
            a.id, 
            p.full_name as patient_name, 
            p.phone,
            a.appointment_date, 
            a.appointment_time, 
            a.status, 
            a.purpose, 
            a.notes
        FROM appointments a
        JOIN patients p ON p.id = a.patient_id
    ";
    
    $params = [];
    if ($start && $end) {
        $query .= " WHERE a.appointment_date >= ? AND a.appointment_date <= ?";
        $params[] = substr($start, 0, 10);
        $params[] = substr($end, 0, 10);
    }
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $appts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $events = [];
    foreach ($appts as $a) {
        // Map status to a color
        $color = '#64748B'; // default secondary
        if ($a['status'] === 'pending') $color = '#268FC8'; // azure blue
        elseif ($a['status'] === 'confirmed') $color = '#235EAE'; // sapphire blue
        elseif ($a['status'] === 'completed') $color = '#10B981'; // success/green
        elseif ($a['status'] === 'cancelled') $color = '#EF4444'; // danger/red
        elseif ($a['status'] === 'no_show') $color = '#475569'; // darker gray
        
        $title = $a['patient_name'] . ' - ' . ucwords(str_replace('_', ' ', $a['purpose']));
        $startDateTime = $a['appointment_date'] . 'T' . $a['appointment_time'];
        
        $events[] = [
            'id' => $a['id'],
            'title' => $title,
            'start' => $startDateTime,
            'backgroundColor' => $color,
            'borderColor' => $color,
            'extendedProps' => [
                'patient_name' => $a['patient_name'],
                'phone' => $a['phone'],
                'status' => $a['status'],
                'purpose' => $a['purpose'],
                'notes' => $a['notes'],
                'time_formatted' => formatTime($a['appointment_time']),
                'date_formatted' => formatDate($a['appointment_date'])
            ]
        ];
    }
    
    echo json_encode($events);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
