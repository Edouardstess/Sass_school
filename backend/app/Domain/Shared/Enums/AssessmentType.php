<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum AssessmentType: string
{
    case Homework = 'homework';
    case Quiz = 'quiz';
    case Exam = 'exam';
    case Project = 'project';
    case Participation = 'participation';

    /** Suggested weight when a teacher creates an assessment of this type. */
    public function defaultWeight(): float
    {
        return match ($this) {
            self::Homework => 1.0,
            self::Quiz => 1.0,
            self::Exam => 3.0,
            self::Project => 2.0,
            self::Participation => 0.5,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Homework => 'Devoir',
            self::Quiz => 'Contrôle',
            self::Exam => 'Examen',
            self::Project => 'Projet',
            self::Participation => 'Participation',
        };
    }
}
