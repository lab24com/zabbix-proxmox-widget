# Proxmox Resources para Zabbix

Widget de panel para visualizar en una sola ventana los recursos principales de Proxmox VE:

- estado, CPU, memoria, disco raíz, uptime, versión, IP y actualizaciones de cada nodo;
- capacidad y uso de los almacenamientos;
- estado y consumo de máquinas virtuales QEMU;
- estado y consumo de contenedores LXC;
- resumen de problemas activos relacionados con los hosts seleccionados.

## Compatibilidad

- Zabbix frontend 7.0 LTS y 7.4.
- Plantilla oficial **Proxmox VE by HTTP**.
- Claves actuales `proxmox_ve.*` y heredadas `proxmox.*`.

Los valores **Usado** y **Total** se muestran cuando la plantilla entrega la capacidad correspondiente. En la plantilla heredada, algunas métricas de capacidad de CPU no existen; en ese caso el widget presenta el porcentaje utilizado y marca el total como `N/D` en lugar de estimarlo.

El widget solo consulta datos existentes mediante la API interna del frontend. No se conecta directamente a la API de Proxmox ni almacena credenciales.

## Instalación

1. Copie `proxmox_resources` dentro de `/usr/share/zabbix/ui/modules/` O /usr/share/zabbix/modules/ en Zabbix 7.0
2. Ajuste propietario y permisos según el usuario de su servidor web. 
3. En Zabbix vaya a **Administración > General > Módulos**.
4. Pulse **Escanear directorio** y habilite **Proxmox Resources**.
5. Agregue el widget al dashboard y seleccione uno o varios hosts con la plantilla oficial.

Ejemplo en Rocky Linux con Nginx/PHP-FPM:

```bash
cd /usr/share/zabbix/ui/modules
unzip -o ProxmoxResources.zip
chown -R nginx:nginx proxmox_resources
find proxmox_resources -type d -exec chmod 755 {} \;
find proxmox_resources -type f -exec chmod 644 {} \;
```

Si no se selecciona ningún host, el widget detecta primero los ítems maestros oficiales `proxmox.cluster.resources` y `proxmox_ve.get_node_data`.
Nota: Para que les funcion al 100% necesitan tener instalado en su VMS Qemu-guest-agent 

