# CrustPHP
<h1>Ease of Use (for all level PHP Developers):</h1><br/>
Best about CrustPHP Framework is that the developer does not need knowledge of Architecture / Framework. Plain (core) PHP Developers can start delivering high-throughput REST-APIs ( / Micro-services) rapidly within hours.<br/><br/>
  
<h1>Ready-made Modules</h1><br/>
It includes DB configurations Module, JWT-based Authentication Module / Middleware, and sample Controllers to enable PHP developers to focus on coding Business Domain Logic.

<h1>CrustPHP Architecture:</h1><br/>
The Architectrue supports development of "Gartner's Event-broker model" through "Enterprise Service Bus" for "dynamic choreography" of Event-driven Microservices.<br/><br/>
CrustPHP enables not only rapid development of Micro-services, but also state-of-the-art dynamic choreography (spinning) of Microservices without Kubernetese.<br/><br/>
(PHP Developers are free write code without HTTP Controllers using Closures) 

<h1>Underlying Technologies:</h1><br/>
CrustPHP encapsulates Phalcon Micro ( ;a compiled PHP-Extension written in C++) inside Swoole ( ;a compiled PHP-Extension for Asynchronous Progamming Models) in oreer to build Concurrent Microservices. Why PHP ? Please see at the end. <br/><br/>
The framework uses lighting fast Phalcon's "Routing Module" in order to map API Reuqests to HTTP Controllers"  

<h1>Integrations in-process:</h1><br/>
To port AI/ML/Data Science libraries PHP-ML and Rubix-ML.

<hr style="height:2px;border-width:0;color:gray;background-color:gray">

<h1>Why Swoole ?</h1><br/>

- Swoole extends PHP with Asynchronous Syntax. It does not require a heavy (resource-hungry hence slower) Web Server/s, running in a separate Process-space than PHP, like APACHE / Ngnix)<br/><br/>

- Swoole enables PHP code (or OPCache code) to load in RAM as Daemon (as an Asynchronous Web and Application Server) making it ultra fast and light-weight (so it is highly suitable for high throughput Micro-services). Swoole also allows IoT and Game Proramming as it supports ease of Network Programing using multiple Network Protocols like MQTT, Web Sockets, TCP, HTTP 1.0 and HTTP 2.0. <br/><br/>

- Unlike, Node.js Swoole spans multiple event-lops on Multiple-processes making effective utilization of all computing (CPU) resource for higher throughput (hence, Cloud-effective). This makes Swoole is not limited to only one Process. However, it may allow single-process environment through simple Configuration settings (Array).<br/><br/>

- Swoole's (pre-emptive) co-routine based Asynchronous Programming Model is superior to asynch/await in .NET and Node as Coroutiens avoid "maintenance complexities" associated with Asycn/Await styled concurrency. Swoole Architecture is inspired by Go-lang and Netty.
