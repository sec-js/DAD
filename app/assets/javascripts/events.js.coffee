# Place all the behaviors and hooks related to the matching controller here.
# All this logic will automatically be available in application.js.
# You can use CoffeeScript in this file: http://coffeescript.org/

draw_graph  =  -> (ajaxData, statusCode, jqXHR)
	jQuery.alert(statusCode)
	new Highcharts.Chart({
		chart:
			renderTo: "big_picture_chart",
		title:
			text: "Big Picture"
		series:
			[{
				type: "pie",
				name: "Aggregate Events",
				data: ajaxData
			}]
	})

big_picture = ->
	jQuery.alert("Fired")
	jQuery.ajax({
		type:'GET',
		url:'/events',
		dataType: 'script',
		success: draw_graph
	})

	


$(document).ready(big_picture)
$(document).on('page:load', big_picture)
