<?php
//~ defined('BASEPATH') OR exit('No direct script access allowed');

Class CApis extends CI_Controller {

    public function __construct() {
        @parent::__construct();

// Load database
        $this->load->model('MTiendasVirtuales');
        $this->load->model('MProductos');
        $this->load->model('MAplicaciones');
        //~ $this->load->model('auditoria/ModelsAuditoria');
        //~ $this->load->model('busquedas_ajax/ModelsBusqueda');
    }
	
	public function mlibre(){
		// Fecha
		$fecha = date('Y-m-d H-i-s');
		// Consultamos los datos de la tienda
		$id = $this->input->get('id');
		$datosb_tienda = $this->MTiendasVirtuales->obtenerTiendas($id);  // Datos básicos de la tienda
		
		//~ print_r($datosb_tienda);
		
		//~ echo "<br><br>";
		
		$datos_aplicacion = $this->MAplicaciones->obtenerAplicacion($datosb_tienda[0]->aplicacion_id);  // Datos de la aplicación asociada
		
		//~ print_r($datos_aplicacion);
		
		$productos = $this->MTiendasVirtuales->obtenerProductosTienda($id);  // Lista de productos asociados
		
		//~ print_r($productos);
		// Si hay productos asociados
		if(count($productos) > 0){
			$meli = new Meli($datosb_tienda[0]->app_id, $datosb_tienda[0]->secret_api);
			if($datosb_tienda[0]->expires_in + time() + 1 < time()){
				$params = array('access_token' => $datosb_tienda[0]->tokens);
				
				// Generamos un archivo con la lista de productos
				$this->list_items($productos, $fecha);
					
				$i = 0;
				$errores = 0;  // Número de errores (Actualizaciones fallidas)
				$num_act = 0;  // Actualizaciones exitosas
				$num_reg = 0;  // Registros exitosos
				$captura_eventos = array();  // Captura de eventos e incidencias al actualizar precios
				foreach($productos as $producto){
					// Consultamos los detalles del producto
					$datos_producto = $this->MProductos->obtenerProductos($producto->producto_id);  // Detalles del producto
					// Consultamos las fotos del producto
					$fotos_producto = $this->MProductos->obtenerFotos($producto->producto_id);  // Fotos del producto
					$lista_fotos = array();
					foreach($fotos_producto as $fotos){
						$lista_fotos[] = array("source"=>base_url()."assets/img/productos/".$fotos->foto);
					}
					// Armamos la descripción a enviar
					$desc = $datos_producto[0]->descripcion;
					
					// Si la tienda virtual tiene fómula especificada le añadimos el cálculo de élla como comisión al precio del producto
					if($datosb_tienda[0]->formula == ""){
						$result = $producto->precio;
						$body = array('price' => round($result, 2), 'available_quantity' => $producto->cantidad, 'pictures' => $lista_fotos);
					}else{
						$precio = $datosb_tienda[0]->formula;
						$p = $producto->precio;
						$f_precio = str_replace('P',$p,$precio);
						eval("\$result = $f_precio;");
						$body = array('price' => round($result, 2), 'available_quantity' => $producto->cantidad, 'pictures' => $lista_fotos);
					}
					$response = $meli->put('/items/'.$producto->referencia, $body, $params);
					//~ print_r($response);
					//~ echo $response['httpCode'];
					if(isset($response['body']->error)){
						$errores++;
						if($response['body']->error == 'not_found'){
							// Procedemos a registrar el nuevo producto en la tienda virtual de mercado libre
							// Constriumos el item a enviar
							$item = array(
								"title" => $datos_producto[0]->nombre,
								"category_id" => "MLV1227",
								"price" => round($result, 2),
								"currency_id" => "VEF",
								"available_quantity" => $producto->cantidad,
								"buying_mode" => "buy_it_now",
								"listing_type_id" => "bronze",
								"condition" => "new",
								"description" => $desc,
								//~ "video_id" => "RXWn6kftTHY",
								//~ "warranty" => "12 month by Ray Ban",
								"pictures" => $lista_fotos  // Arreglo con lista de fotos
							);
							
							// Ejecutamos el método de envío de ítems
							$response_reg = $meli->post('/items', $item, $params);
							// Aumentamos el contador de registros si el ítem fue registrado correctamente
							if($response_reg['httpCode'] == '201'){
								$num_reg++;
								// Registro de incidencia
								//~ $this->logs($producto->producto_id, $response_reg['body']->id, "Registrado...", $this->session->userdata['logged_in']['id'], $fecha);
								$captura_eventos[] = "[".date("r")."] Producto: ".$producto->producto_id.", Num Referencia: ".$response_reg['body']->id.", Evento: Registrado..., Usuario: ".$this->session->userdata['logged_in']['id']."\r\n";
								//~ print_r($response_reg);
								// Actualizamos el código de referencia en la tabla de asociaciones de productos con tiendas virtuales 'productos_tiendav'
								$cod_ref = $response_reg['body']->id;
								//~ $cod_ref = explode('MLV', $cod_ref);
								//~ $cod_ref = $cod_ref[1];
								$data_referencia = array(
									'producto_id' => $producto->producto_id, 
									'tiendav_id' => $id,
									'referencia' => $cod_ref
								);
								$update_referencia = $this->MTiendasVirtuales->update_tp($data_referencia);
							}
						}else{
							// Registro de incidencia
							//~ $this->logs($producto->producto_id, $producto->referencia, $response['body']->error, $this->session->userdata['logged_in']['id'], $fecha);
							$captura_eventos[] = "[".date("r")."] Producto: ".$producto->producto_id.", Num Referencia: ".$producto->referencia.", Evento: ".$response['body']->error.", Usuario: ".$this->session->userdata['logged_in']['id']."\r\n";
						}
					}else{
						// Si no hubo errores en el envío del precio y la cantidad, entonces enviamos la descripción
						$body = array('text' => $desc);
						$response_desc = $meli->put('/items/'.$producto->referencia.'/description', $body, $params);
						if(isset($response_desc['body']->error)){
							print_r($response_desc);
						}
					}
					if($response['httpCode'] == '200'){
						$num_act++;
						// Registro de incidencia
						//~ $this->logs($producto->producto_id, $response['body']->id, "Actualizado...", $this->session->userdata['logged_in']['id'], $fecha);
						$captura_eventos[] = "[".date("r")."] Producto: ".$producto->producto_id.", Num Referencia: ".$response['body']->id.", Evento: Actualizado..., Usuario: ".$this->session->userdata['logged_in']['id']."\r\n";
					}
					//~ echo "<br>";
					//~ echo "<br>";
					$i++;
				}
				// Generamos el log
				$this->logs($captura_eventos, $fecha);
				
				$this->load->view('base');
				$data['mensaje'] = "Ha actualizado los precios con exito!";
				$data['num_act'] = $num_act;
				$data['errores'] = $errores;
				$data['registros'] = $num_reg;
				$this->load->view('price_update', $data);
				$this->load->view('footer');
			}else{
				if(isset($_GET['code'])) {
					//~ // If the code was in get parameter we authorize
					$user = $meli->authorize($_GET['code'], base_url().'mercado/update?id='.$id);
					//~ 
					//~ // Now we create the sessions with the authenticated user
					if(isset($user['body']->access_token)){
						$_SESSION['access_token'] = $user['body']->access_token;
						$_SESSION['expires_in'] = $user['body']->expires_in;
						//~ $_SESSION['refrsh_token'] = $user['body']->refresh_token;
						//~ print_r($_SESSION['access_token']);
						//~ print_r($_SESSION['expires_in']);
						
						// Registramos el token y el tiempo en base de datos
						$datos = array(
							'id' => $id,
							'nombre' => $datosb_tienda[0]->nombre,
							'tokens' => $user['body']->access_token,
							'expires_in' => $user['body']->expires_in
						);
						
						$result = $this->MTiendasVirtuales->update($datos);
						
						//~ // We can check if the access token in invalid checking the time
						if($_SESSION['expires_in'] + time() + 1 < time()) {
							try {
								print_r($meli->refreshAccessToken());
							} catch (Exception $e) {
								echo "Exception: ",  $e->getMessage(), "\n";
							}
						}
						
						$params = array('access_token' => $_SESSION['access_token']);
						
						// Generamos un archivo con la lista de productos
						$this->list_items($productos, $fecha);
						
						$i = 0;
						$errores = 0;  // Número de errores (Actualizaciones fallidas)
						$num_act = 0;  // Actualizaciones exitosas
						$num_reg = 0;  // Registros exitosos
						$captura_eventos = array();  // Captura de eventos e incidencias al actualizar precios
						foreach($productos as $producto){
							// Consultamos los detalles del producto
							$datos_producto = $this->MProductos->obtenerProductos($producto->producto_id);  // Detalles del producto
							// Consultamos las fotos del producto
							$fotos_producto = $this->MProductos->obtenerFotos($producto->producto_id);  // Fotos del producto
							$lista_fotos = array();
							foreach($fotos_producto as $fotos){
								$lista_fotos[] = array("source"=>base_url()."assets/img/productos/".$fotos->foto);
							}
							// Armamos la descripción a enviar
							$desc = $datos_producto[0]->descripcion;
							
							// Si la tienda virtual tiene fómula especificada le añadimos el cálculo de élla como comisión al precio del producto
							if($datosb_tienda[0]->formula == ""){
								$result = $producto->precio;
								$body = array('price' => round($result, 2), 'available_quantity' => $producto->cantidad, 'pictures' => $lista_fotos);
							}else{
								$precio = $datosb_tienda[0]->formula;
								$p = $producto->precio;
								$f_precio = str_replace('P',$p,$precio);
								eval("\$result = $f_precio;");
								$body = array('price' => round($result, 2), 'available_quantity' => $producto->cantidad, 'pictures' => $lista_fotos);
							}
							//~ $body = array('price' => $producto->precio);

							$response = $meli->put('/items/'.$producto->referencia, $body, $params);
							//~ print_r($response);
							//~ echo $response['httpCode'];
							if(isset($response['body']->error)){
								$errores++;
								//~ echo $producto->referencia;
								//~ print_r($response);
								if($response['body']->error == 'not_found'){
									// Procedemos a registrar el nuevo producto en la tienda virtual de mercado libre
									// Constriumos el item a enviar
									$item = array(
										"title" => $datos_producto[0]->nombre,
										"category_id" => "MLV1227",
										"price" => round($result, 2),
										"currency_id" => "VEF",
										"available_quantity" => $producto->cantidad,
										"buying_mode" => "buy_it_now",
										"listing_type_id" => "bronze",
										"condition" => "new",
										"description" => $desc,
										//~ "video_id" => "RXWn6kftTHY",
										//~ "warranty" => "12 month by Ray Ban",
										"pictures" => $lista_fotos  // Arreglo con lista de fotos
									);
									
									// Ejecutamos el método de envío de items
									$response_reg = $meli->post('/items', $item, $params);
									// Aumentamos el contador de registros si el ítem fue registrado correctamente
									if($response_reg['httpCode'] == '201'){
										$num_reg++;
										// Registro de incidencia
										//~ $this->logs($producto->producto_id, $response_reg['body']->id, "Registrado...", $this->session->userdata['logged_in']['id'], $fecha);
										$captura_eventos[] = "[".date("r")."] Producto: ".$producto->producto_id.", Num Referencia: ".$response_reg['body']->id.", Evento: Registrado..., Usuario: ".$this->session->userdata['logged_in']['id']."\r\n";
										//~ print_r($response_reg);
										// Actualizamos el código de referencia en la tabla de asociaciones de productos con tiendas virtuales 'productos_tiendav'
										$cod_ref = $response_reg['body']->id;
										//~ $cod_ref = explode('MLV', $cod_ref);
										//~ $cod_ref = $cod_ref[1];
										$data_referencia = array(
											'producto_id' => $producto->producto_id, 
											'tiendav_id' => $id,
											'referencia' => $cod_ref
										);
										$update_referencia = $this->MTiendasVirtuales->update_tp($data_referencia);
									}
								}else{
									// Registro de incidencia
									//~ $this->logs($producto->producto_id, $producto->referencia, $response['body']->error, $this->session->userdata['logged_in']['id'], $fecha);
									$captura_eventos[] = "[".date("r")."] Producto: ".$producto->producto_id.", Num Referencia: ".$producto->referencia.", Evento: ".$response['body']->error.", Usuario: ".$this->session->userdata['logged_in']['id']."\r\n";
								}
							}else{
								// Si no hubo errores en el envío del precio y la cantidad, entonces enviamos la descripción
								$body = array('text' => $desc);
								$response_desc = $meli->put('/items/'.$producto->referencia.'/description', $body, $params);
								if(isset($response_desc['body']->error)){
									print_r($response_desc);
								}
							}
							if($response['httpCode'] == '200'){
								$num_act++;
								// Registro de incidencia
								//~ $this->logs($producto->producto_id, $response['body']->id, "Actualizado...", $this->session->userdata['logged_in']['id'], $fecha);
								$captura_eventos[] = "[".date("r")."] Producto: ".$producto->producto_id.", Num Referencia: ".$response['body']->id.", Evento: Actualizado..., Usuario: ".$this->session->userdata['logged_in']['id']."\r\n";
							}
							//~ echo "<br>";
							//~ echo "<br>";
							$i++;
						}
						// Generamos el log
						$this->logs($captura_eventos, $fecha);
						
						$this->load->view('base');
						$data['mensaje'] = "Ha actualizado los precios con exito!";
						$data['num_act'] = $num_act;
						$data['errores'] = $errores;
						$data['registros'] = $num_reg;
						$this->load->view('price_update', $data);
						$this->load->view('footer');
					}else{
						$redirect = $meli->getAuthUrl(base_url().'mercado/update?id='.$id, Meli::$AUTH_URL['MLV']);
						redirect($redirect);
					}
				} else {
					//~ echo "ingreso2";
					//~ echo '<a href="' . $meli->getAuthUrl(base_url().'mercado/update?id='.$id, Meli::$AUTH_URL['MLV']) . '">Login</a>';
					$redirect = $meli->getAuthUrl(base_url().'mercado/update?id='.$id, Meli::$AUTH_URL['MLV']);
					redirect($redirect);
				}
			}
		}else{
			$this->load->view('base');
			$data['mensaje'] = "No hubo cambios!";
			$this->load->view('price_update', $data);
			$this->load->view('footer');
		}
		
	}
	
	public function olx($id){
		$id = $this->input->get('id');
		$productos = $this->MTiendasVirtuales->obtenerProductosTienda($id);
		
		print_r($productos);
		
		echo count($productos);
	}
	
	public function prestashop($id){
		$id = $this->input->get('id');
		$productos = $this->MTiendasVirtuales->obtenerProductosTienda($id);
		
		print_r($productos);
		
		echo count($productos);
	}
	
	// Genera un json con los datos de un producto por id dado
	public function product()
    {
		$data['id'] = $this->uri->segment(3);
        $result = $this->MProductos->obtenerProductos($data['id']);
        echo json_encode($result);
    }
    
    // Método público para generar un registro de los eventos sucedidos durante una sincronización con la tienda virtual de Mercado Libre
    function logs($eventos, $fecha)
    {
		$ruta = getcwd();  // Obtiene el directorio actual en donde se esta trabajando
		
        $ddf = fopen($ruta.'/application/logs/logs_'.$fecha.'.log','a');
		foreach($eventos as $evento){
			fwrite($ddf, $evento);
		}
		
		fclose($ddf);
		
    }
    // Método público para generar un registro de los eventos sucedidos durante una sincronización con la tienda virtual de Mercado Libre
    function list_items($list_items, $fecha)
    {
		$ruta = getcwd();  // Obtiene el directorio actual en donde se esta trabajando
		
        $ddf = fopen($ruta.'/application/logs/list_items_'.$fecha.'.log','a');
		
		foreach($list_items as $producto){
			fwrite($ddf,"Producto id: ".$producto->producto_id.", Tienda id: ".$producto->tiendav_id.", Num Referencia: ".$producto->referencia.", Precio: ".$producto->precio.", Cantidad: ".$producto->cantidad."\r\n");
		}
		
		fclose($ddf);
		
    }

}
