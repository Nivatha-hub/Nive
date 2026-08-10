document.getElementById("loginForm").addEventListener("submit", function(e) {
e.preventDefault();

const email = document.querySelector('input[type="email"]').value;  
const password = document.querySelector('input[type="password"]').value;  

const correctEmail = "employer@gmail.com";  
const correctPassword = "12345";  

if (email === correctEmail && password === correctPassword) {  
    window.location.href = "dashboard.html";  
} else {  
    alert("Invalid email or password");  
}

});

