<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

/**
 * Échelon d'entrée d'un profil introuvable dans le jeu importé : aucune grille pour
 * son (corps, grade), ou rang d'entrée absent de la grille retenue.
 */
final class UnknownEntryEchelonException extends ImportException
{
}
