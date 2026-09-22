<?php
require_once __DIR__ . '/../src/core/bootstrap.php';
require_once __DIR__ . '/../src/modules/m5_batch_slots/service.php';

echo "Checking unbatched eligible candidates for Assessment 1...\n";
$eligible = get_unbatched_eligible_count(1, $conn);
echo "Eligible count: {$eligible}\n";

if ($eligible > 0) {
    echo "Triggering batch creation with threshold = 1...\n";
    $batches = create_all_eligible_batches(1, $conn, 1);
    echo "Batches formed: " . count($batches) . "\n";
    foreach ($batches as $b) {
        echo " - Batch ID: " . $b['batch_id'] . " (" . $b['batch_number'] . ")\n";
        if (!empty($b['slots'])) {
            echo "   Created " . count($b['slots']) . " slots.\n";
            foreach ($b['slots'] as $s) {
                echo "     Slot ID " . $s['slot_id'] . ": " . $s['slot_time'] . " (" . $s['available_seats'] . " seats)\n";
            }
        }
    }
} else {
    echo "No eligible candidates found or candidates already batched.\n";
}
