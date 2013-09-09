# Syncrotron Module

A module that provides an interface for syncronising content between 
multiple SilverStripe instances, using GUIDs for tracking definitive 
version of a content item. 

## Maintainer Contact

Marcus Nyeholt

<marcus (at) silverstripe (dot) com (dot) au>

## Requirements

* SilverStripe 3.1
* Webservices module
* Content Changesets module (recommended)
* QueuedJobs module

## Documentation

* Add the SyncroableExtension to the objects you want syncronised between
  instances. 
* configure a RemoteSyncroNode object pointing at a remote 
  system and call the `SyncrotronService->getUpdates()` method

To access the list of updates programmatically,

* Note the "Master Node ID" from the settings tab of your system
* call the `jsonservice/Syncrotron/listUpdates` endpoint to get the list 
  of updates, passing the following parameters
  * since - the Y-m-d H:i:s date after which to get updates
  * system - your master node ID

With the Changesets module installed, you can explicitly deploy a content 
changeset to a remote node. 

## Quick Usage Overview

## API

## Troubleshooting

