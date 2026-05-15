-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Servidor: localhost:3306
-- Tiempo de generación: 15-05-2026 a las 13:18:39
-- Versión del servidor: 11.4.10-MariaDB-cll-lve-log
-- Versión de PHP: 8.4.21

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `ofinovac_doli904`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `llxu3_ofinova_nom_detalles`
--

CREATE TABLE `llxu3_ofinova_nom_detalles` (
  `rowid` int(11) NOT NULL,
  `fk_periodo` int(11) NOT NULL,
  `fk_soc` int(11) NOT NULL,
  `dias_trabajados` int(11) DEFAULT 30,
  `sueldo_base` double DEFAULT 0,
  `val_sueldo_pagar` double DEFAULT 0,
  `val_aux_trans` double DEFAULT 0,
  `val_salud` double DEFAULT 0,
  `val_pension` double DEFAULT 0,
  `val_anticipos` double DEFAULT 0,
  `total_neto` double DEFAULT 0,
  `fk_user_crea` int(11) DEFAULT NULL,
  `datec` datetime DEFAULT current_timestamp(),
  `val_arl_patronal` double DEFAULT 0,
  `val_pension_patronal` double DEFAULT 0,
  `val_salud_patronal` double DEFAULT 0,
  `val_caja_comp` double DEFAULT 0,
  `val_sena` double DEFAULT 0,
  `val_icbf` double DEFAULT 0,
  `val_cesantias` double DEFAULT 0,
  `val_intereses_ces` double DEFAULT 0,
  `val_prima` double DEFAULT 0,
  `val_vacaciones` double DEFAULT 0,
  `dias_vacaciones_disfrute` int(11) DEFAULT 0,
  `val_vacaciones_pagadas` double DEFAULT 0,
  `status_pago` tinyint(1) DEFAULT 0,
  `fk_bank_pago` int(11) DEFAULT NULL,
  `status_pago_eps` tinyint(1) DEFAULT 0,
  `status_pago_afp` tinyint(1) DEFAULT 0,
  `status_pago_arl` tinyint(1) DEFAULT 0,
  `status_pago_caja` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Volcado de datos para la tabla `llxu3_ofinova_nom_detalles`
--

INSERT INTO `llxu3_ofinova_nom_detalles` (`rowid`, `fk_periodo`, `fk_soc`, `dias_trabajados`, `sueldo_base`, `val_sueldo_pagar`, `val_aux_trans`, `val_salud`, `val_pension`, `val_anticipos`, `total_neto`, `fk_user_crea`, `datec`, `val_arl_patronal`, `val_pension_patronal`, `val_salud_patronal`, `val_caja_comp`, `val_sena`, `val_icbf`, `val_cesantias`, `val_intereses_ces`, `val_prima`, `val_vacaciones`, `dias_vacaciones_disfrute`, `val_vacaciones_pagadas`, `status_pago`, `fk_bank_pago`, `status_pago_eps`, `status_pago_afp`, `status_pago_arl`, `status_pago_caja`) VALUES
(29, 15, 41, 30, 1750905, 1750905, 249095, 70036.2, 70036.2, 1246621.15, 613306.45, 1, '2026-05-13 23:03:07', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, NULL, 0, 0, 0, 0),
(30, 15, 55, 30, 1750905, 1750905, 249095, 70036.2, 70036.2, 714880, 1145047.6, 1, '2026-05-13 23:03:07', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, NULL, 0, 0, 0, 0);

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `llxu3_ofinova_nom_detalles`
--
ALTER TABLE `llxu3_ofinova_nom_detalles`
  ADD PRIMARY KEY (`rowid`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `llxu3_ofinova_nom_detalles`
--
ALTER TABLE `llxu3_ofinova_nom_detalles`
  MODIFY `rowid` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
