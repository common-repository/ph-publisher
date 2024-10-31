var z = document.getElementById("json").innerHTML;
var y = JSON.parse(z);
var yLength = y.length;

var valuesArray = [];

for (var i = 0; i < yLength; i++) {
	var ph_publisher_links = [{text: y[i].campaign_title, value: 'https://prf.hn/click/camref:' + y[i].camref}];
	valuesArray = valuesArray.concat(ph_publisher_links);
}

(function() {
	tinymce.create("tinymce.plugins.ph_publisher_links", {
		init : function(ed, url) {
			ed.addButton("ph_publisher_links", {
				title : 'PH Publisher Links',
				image : url + '/ph_icon.png',
				onclick : function() {
					// Open a TinyMCE modal
					ed.windowManager.open({
						title: 'Insert PH Tracking Link',
						body: [{
							type: 'listbox',
							name: 'link',
							label: 'Choose a Campaign',
							values: valuesArray,
						},{
							type: 'textbox',
							name: 'anchor',
							label: 'Link Text'
						},{
							type: 'textbox',
							name: 'pubref',
							label: 'Publisher Reference'
						},{
							type: 'textbox',
							name: 'adref',
							label: 'Advertiser Reference'
						},{
							type: 'textbox',
							name: 'destination',
							label: 'Deeplink'
						}],
						onsubmit: function( e ) {
							if (e.data.link !== "")
							{
								var link = e.data.link;
								var anchor = e.data.anchor;
								var pubref = e.data.pubref;
								var adref = e.data.adref;
								var destination = e.data.destination;
								if (pubref !== "")
								{
									link = link + '/pubref:' + encodeURIComponent( pubref );
								}
								if (adref !== "")
								{
									link = link + '/adref:' + encodeURIComponent( adref );
								}
								if (destination !== "")
								{
									link = link + '/destination:' + encodeURIComponent( destination );
								}

								ed.insertContent( '<a href="' + link + '" target="_blank">' + anchor + '</a>' );
							}
							else
							{
								return false;
							}
						}
					});
				}
			});
		},
		createControl : function(n, cm) {
			return null;
		},
		getInfo : function() {
			return {
				longname : "PH Publisher Links",
				author : "Performance Horizon",
				version : "1.1.5"
			};
		}
	});
	tinymce.PluginManager.add("ph_publisher_links", tinymce.plugins.ph_publisher_links);
})();