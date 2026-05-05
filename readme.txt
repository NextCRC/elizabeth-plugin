=== Elizabeth - Customer Service ===
Contributors: nextcrc
Tags: ai, chatbot, sales agent, woocommerce, customer service
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Elizabeth es una agente de ventas y servicio al cliente impulsada por inteligencia artificial, diseñada para tiendas WooCommerce.

== Description ==

Elizabeth se integra con tu tienda WooCommerce para brindar atención al cliente en tiempo real. Responde preguntas sobre productos, precios, disponibilidad y políticas de la tienda de forma automática, las 24 horas del día.

Características principales:

* Chat inteligente con IA entrenada con el catálogo real de tu tienda
* Integración nativa con WooCommerce (productos, precios, stock)
* Base de conocimiento personalizable por sitio
* Personalidad del agente configurable
* Soporte para múltiples sitios (según plan)
* Historial de conversaciones en el panel de control
* Actualizaciones automáticas desde el servidor de NextCRC

== Requirements ==

* WordPress 6.0 o superior
* PHP 7.4 o superior
* WooCommerce 7.0 o superior (recomendado)
* Licencia activa de Elizabeth — obtén la tuya en https://elizabeth.nextcrc.com

== Installation ==

1. Descarga el archivo `elizabeth-plugin.zip` desde tu panel de control en https://elizabeth.nextcrc.com
2. En WordPress ve a Plugins → Añadir nuevo → Subir plugin
3. Selecciona el archivo .zip y haz clic en "Instalar ahora"
4. Activa el plugin
5. Ve a Elizabeth AI en el menú lateral de WordPress
6. Ingresa tu User ID y License Key (disponibles en tu panel de control)
7. Guarda los ajustes — el chat aparecerá automáticamente en el frontend

Para instrucciones detalladas con capturas de pantalla visita:
https://elizabeth.nextcrc.com/docs/instalacion

== Frequently Asked Questions ==

= ¿Necesito una cuenta para usar el plugin? =
Sí. Elizabeth requiere una licencia activa. Regístrate en https://elizabeth.nextcrc.com

= ¿Funciona sin WooCommerce? =
El plugin funciona sin WooCommerce, pero las funciones de catálogo y precios en tiempo real requieren WooCommerce instalado y activo.

= ¿Cómo configuro la personalidad del agente? =
Desde el panel de control en https://elizabeth.nextcrc.com, en la sección "Sitios", puedes editar el prompt de personalidad de cada sitio.

= ¿Los datos de mis clientes son seguros? =
Sí. Elizabeth no almacena información personal identificable de los visitantes. El historial de conversaciones se guarda de forma anónima vinculado a tu cuenta.

= ¿Cómo actualizo el plugin? =
Las actualizaciones aparecen automáticamente en WordPress → Plugins, igual que cualquier otro plugin. Solo necesitas tener una licencia activa.

= ¿Dónde obtengo soporte? =
Escríbenos a soporte@nextcrc.com o visita https://elizabeth.nextcrc.com/soporte

== Changelog ==

= 1.0.2 =
* Mejoras de seguridad: detección de IP real detrás de Cloudflare/proxies en rate limiting
* Retardo de respuesta configurable desde el panel de administración (5-60 s)
* Constante SYNC_INVENTORY_ENDPOINT extraída para mejor mantenibilidad
* Verificación de integridad de actualizaciones más específica (dominio del proyecto)
* Corrección XSS en lockWidget del frontend
* BASE_DELAY del chat precalculado una sola vez por sesión

= 1.0.1 =
* Sistema de actualizaciones automáticas desde servidor propio
* Mejoras de rendimiento en carga del catálogo
* Correcciones menores de estabilidad

= 1.0.0 =
* Lanzamiento inicial

== Links de interés ==

* Sitio oficial: https://elizabeth.nextcrc.com
* Panel de control: https://elizabeth.nextcrc.com/dashboard
* Documentación: https://elizabeth.nextcrc.com/docs
* Soporte: https://elizabeth.nextcrc.com/soporte
* NextCRC: https://nextcrc.com
