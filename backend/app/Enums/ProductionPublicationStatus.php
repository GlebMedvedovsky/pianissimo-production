<?php

namespace App\Enums;

enum ProductionPublicationStatus: string
{
    case Preparing = 'preparing';
    case Prepared = 'prepared';
    case Dispatching = 'dispatching';
    case Dispatched = 'dispatched';
    case Building = 'building';
    case Built = 'built';
    case Uploading = 'uploading';
    case Uploaded = 'uploaded';
    case Activating = 'activating';
    case Active = 'active';
    case Failed = 'failed';
    case Superseded = 'superseded';
    case RolledBack = 'rolled_back';
}
