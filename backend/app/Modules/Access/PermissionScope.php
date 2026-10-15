<?php

namespace App\Modules\Access;

enum PermissionScope: string
{
    case Own = 'own';
    case AssignedBranches = 'assigned_branches';
    case EntireClinic = 'entire_clinic';
}
