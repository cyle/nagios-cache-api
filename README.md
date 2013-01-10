# Nagios Cache and API

I use [Nagios](http://www.nagios.org/) to monitor a TON of servers and services for my job. At Emerson College, at the time of this writing, we have just under 300 hosts and over 500 service checks. That's a lot, I think.

Nagios is fantastic, and I recommend it to sysadmins all the time. However, it's a pain to get information out of it. The system that is monitoring all the other systems is the LAST system I want to be putting more load on, so I built this system to cache Nagios information and an API to serve it.

I use this API to build more user-friendly versions of current server statuses. It's pretty simple: the cache script runs once every five minutes, grabs the latest information from Nagios, and updates a MongoDB database with it in a much more usable format. The API queries this database. I only keep the most relevant information: the hostname, when it was last checked, service statuses, and any error messages that may be helpful. The cache also keeps track of how long something is broken.

The API takes URL query parameters via GET or POST and returns JSON. I would strongly recommend not installing MongoDB on the Nagios server. In the simplest scenario, you would have a web server with MongoDB to act as the API part, and your Nagios server with the cache updating script which connects to the MongoDB instance on your web server.

## Requirements

### The Cache Part

- PHP 5.3+
- the "mongo" PHP PECL extension 
- MongoDB 2.0+ somewhere
- Nagios! Well, yeah.

### The API Part

- A linux-based web server, Apache or lighttpd or whatever
- PHP 5.3+
- the "mongo" PHP PECL extension

## Notes

Included are two files, "hosts.php" and "hosts\_save.php", that serve to edit "friendly names" of hosts, if you want. I use this because nobody knows certain servers by what they are called in Nagios, but they do know it as "The Emerson Website".

There is the capacity to create special "groups" of hosts that will appear via the API as a single host -- I created that because we wanted whole buildings of switches or clusters of servers to be considered singular. You can use this if you want.

The cache also registers things not on the same scale as Nagios. In the cache, there are separate attributes for when something is BROKEN -- as in, totally unusable and requires immediate attention -- and when something just has a problem. Certain service checks, like check\_ping, probably mean it's broken when they fail. However, check\_smtp, maybe not so much. But it is a problem. You can tweak this list of "broken" service checks in "nag\_mongo.php".

## Installing

On the Nagios server, you need to install the contents of the "nagios" folder somewhere. Does not matter where. The "nag\_mongo.php" script needs to be run every five minutes using cron, which looks something like this:

<code>*/5 * * * * /usr/bin/php /usr/local/lib/nag_mongo.php</code>

To install the API, put the contents of the "www" folder somewhere that is NOT the Nagios server. You should update the links in the "api.html" file, as they're very generic and you should be able to make them relevant to your deployment. That should be it.

