
# devbot
The DevBot App

![PHP](https://img.shields.io/badge/PHP-8%2B-blue)
![Status](https://img.shields.io/badge/Status-Active%20Development-orange)
![License](https://img.shields.io/badge/License-TBD-lightgrey)
![Sponsored](https://img.shields.io/badge/Sponsored-STN_Labz-blue)

The PHP Developers companion.

DevBot is the reporting layer of the STN-Labz ecosystem. 

## What it does
 - It passively scans projects, 
 - reads plugin signals, 
 - monitors file activity, 
 - records development conditions. 
 
 Each run produces structured reports that help track progress, identify changes, and document the state of the system over time.

 ### MVC Specific
 In an MVC environment, it seeks the core outer (commonly found in `/app/core/router.php`), and then traverses the controllers tree `/app/controllers`) and from there ensures that classes are present, looks into your functions, maps to models and views, and if any of that is a mess, it will tell you. 
 
 ## About
 DevBot acts as a transparent observer — a diagnostic and historical tool that keeps clear, consistent insight into ongoing work without ever interfering with it.

## How to use
 - Drop devbot in yuor websites root
 - navigate to `pubroot/devbot/config` and edit `devbot_config.json`
 - navigate back to the devbot root
 - `php devbot.php`

## Common Platforms
 - Chaos CMS
 - Standard MVC Platforms
