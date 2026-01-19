<?php
// Plugin: mood.php
// Purpose: Add a randomly chosen "tone" or "mood" to the devlog entry

$moods = [
    "?? Gothic, with whispers of candle smoke and cold stone.",
    "?? Industrial grit — rust on chrome, oil on code.",
    "?? Formal and reverent, as if written by a cloistered monk with WiFi.",
    "?? Cozy patchwork — today was stitched together with care.",
    "??? Chaotic neutral — code collided, survived, and somehow ran.",
    "?? Eldritch — something shifted beneath the stack trace.",
    "?? Fired up — progress crackled like dry timber.",
    "?? Cold, efficient, surgical. Everything clicked… almost too well.",
    "?? Elegant — the code danced like red wine in a thin glass.",
    "?? Slightly unhinged — functions rewired themselves in glee.",
    "?? Witchy - the fire is hot, the cauldron is boiling."
];

// Optional: seed based on the day so mood is consistent per date
srand(strtotime($today));
$mood = $moods[array_rand($moods)];

$todays_logs[] = "?? Rictus Mood Today:\n\n\"$mood\"";