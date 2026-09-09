<?php

declare(strict_types=1);

return [
    'enrollment_title' => 'Certificat de scolarité',
    'attendance_title' => 'Attestation de fréquentation',
    'completion_title' => 'Certificat de réussite',
    'transcript_title' => 'Relevé de notes',

    'enrollment_body' => "Le Directeur de <strong>:school</strong> certifie que l'élève <strong>:student</strong>, matricule <strong>:matricule</strong>, est régulièrement inscrit(e) en classe de <strong>:class</strong> pour l'année scolaire <strong>:year</strong>.<br><br>Le présent certificat est délivré à l'intéressé(e) pour servir et valoir ce que de droit.",
    'attendance_body' => "Le Directeur de <strong>:school</strong> atteste que l'élève <strong>:student</strong>, matricule <strong>:matricule</strong>, a fréquenté l'établissement en classe de <strong>:class</strong> durant l'année scolaire <strong>:year</strong>.",
    'completion_body' => "Le Directeur de <strong>:school</strong> certifie que l'élève <strong>:student</strong>, matricule <strong>:matricule</strong>, a satisfait aux exigences de la classe de <strong>:class</strong> au titre de l'année scolaire <strong>:year</strong>.",
    'generic_body' => "Le Directeur de <strong>:school</strong> certifie que l'élève <strong>:student</strong> (matricule <strong>:matricule</strong>) était inscrit(e) en <strong>:class</strong> pour l'année <strong>:year</strong>.",

    'not_found' => 'Aucun document ne correspond à ce code de vérification.',
    'issued' => 'Le document a été délivré.',
    'revoked' => 'Le document a été révoqué.',
];
